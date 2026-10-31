<?php

namespace Ernestdefoe\Kiosk\Provider;

use Ernestdefoe\Kiosk\Seller\Account;
use Ernestdefoe\Kiosk\Seller\Fees;
use Ernestdefoe\Kiosk\Seller\PaysTo;
use Ernestdefoe\Kiosk\Seller\SellerProvider;

use Carbon\Carbon;
use Ernestdefoe\Kiosk\Model\Payment;
use Ernestdefoe\Kiosk\Model\Price;
use Ernestdefoe\Kiosk\Model\Product;
use Ernestdefoe\Kiosk\Model\Purchase;
use Ernestdefoe\Kiosk\Service\Fulfilment;
use Flarum\Foundation\ValidationException;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\Locale\TranslatorInterface;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Psr\Log\LoggerInterface;

/**
 * Stripe for Kiosk, through Stripe Checkout: the buyer pays on Stripe's page
 * with whatever the owner has switched on there (cards, Apple Pay, Google
 * Pay, Link, bank debits, stablecoins…). One-off and fixed-term prices are
 * Checkout in payment mode; recurring prices are Stripe Billing
 * subscriptions, which bring retries, card updates and SCA for free.
 *
 * The Stripe product and price behind a Kiosk price are made the first time
 * anyone buys it. A purchase's provider_ref is the Checkout session until a
 * subscription exists, then the subscription.
 *
 * 🚨 As with PayPal: the return page is never believed, webhooks are
 * signature-checked before they are read, and amounts are compared.
 */
class Stripe implements Provider, WebhookProvider, SellerProvider
{
    use PaysTo;

    public function __construct(
        private StripeClient $api,
        private Fulfilment $fulfilment,
        private ConnectionInterface $db,
        private TranslatorInterface $translator,
        private LoggerInterface $log,
        private SettingsRepositoryInterface $settings,
    ) {
    }

    public function key(): string
    {
        return 'stripe';
    }

    public function isReady(): bool
    {
        if (! $this->api->isConnected()) {
            return false;
        }

        // A member's account also needs the forum's Connect webhook, or their
        // buyers' payments would never be heard about.
        return ! $this->payee || ($this->payee->isConnected() && $this->api->setting('connect_webhook_secret') !== '');
    }

    /** A copy that charges directly on a member's connected account, taking the forum's fee. */
    public function forAccount(Account $account): static
    {
        $copy = clone $this;
        $copy->payee = $account;
        $copy->api = $this->api->connected($account->externalId());

        return $copy;
    }

    public function mode(): string
    {
        return $this->api->mode();
    }

    public function client(): StripeClient
    {
        return $this->api;
    }

    public function supports(Price $price): bool
    {
        return true;
    }

    public function checkout(Purchase $purchase, Price $price, Product $product, string $returnUrl, string $cancelUrl): string
    {
        $recurring = $price->isRecurring();
        $user = User::query()->find($purchase->gifted_by ?? $purchase->user_id);

        $params = [
            'mode' => $recurring ? 'subscription' : 'payment',
            'line_items' => [$this->lineItem($purchase, $price, $product)],
            // Stripe swaps in the session id, which the return page hands back.
            'success_url' => $returnUrl.'?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $cancelUrl,
            'client_reference_id' => $purchase->number,
            'metadata' => ['kiosk_number' => $purchase->number],
            'customer_email' => $user?->email,
        ];

        // Stripe Tax, when the owner turned it on: Stripe works out and adds
        // the tax for the buyer's address. Kiosk still asks for its price.
        // (Not on a member's sale: their tax is theirs, set up in their account.)
        if (! $this->payee && $this->settings->get('ernestdefoe-kiosk.stripe_tax')) {
            $params['automatic_tax'] = ['enabled' => true];
            $params['billing_address_collection'] = 'required';
        }

        if ($recurring) {
            $params['subscription_data'] = ['metadata' => ['kiosk_number' => $purchase->number]]
                + ($price->trial_days > 0 ? ['trial_period_days' => $price->trial_days] : []);
        } else {
            $params['payment_intent_data'] = ['metadata' => ['kiosk_number' => $purchase->number], 'description' => mb_substr($purchase->product_name, 0, 200)];
        }

        // A member's sale: the forum's cut, taken by Stripe from their charge.
        if ($this->payee) {
            $fees = resolve(Fees::class);
            if ($recurring && $fees->percent() > 0) {
                $params['subscription_data']['application_fee_percent'] = $fees->percent();
            } elseif (! $recurring && ($cut = $fees->cut($purchase->amount_cents)) > 0) {
                $params['payment_intent_data']['application_fee_amount'] = $cut;
            }
        }

        $session = $this->api->call('POST', '/v1/checkout/sessions', array_filter($params, fn ($v) => $v !== null), 'kiosk-'.$purchase->number);

        if (! $session || ! isset($session['id'], $session['url'])) {
            throw new ValidationException(['provider' => $this->translator->trans(
                $session === null ? 'ernestdefoe-kiosk.forum.errors.provider_unreachable' : 'ernestdefoe-kiosk.forum.errors.provider_refused'
            )]);
        }

        $purchase->provider_ref = (string) $session['id'];
        $purchase->save();

        return (string) $session['url'];
    }

    /**
     * The checkout's line: the stored Stripe price, or — when this purchase
     * costs something else (pay what you want, a coupon) — the amount inline.
     *
     * @return array<string, mixed>
     */
    private function lineItem(Purchase $purchase, Price $price, Product $product): array
    {
        if ($purchase->amount_cents === $price->amount_cents && $price->kind !== Price::CUSTOM) {
            return ['price' => $this->price($price, $product), 'quantity' => 1];
        }

        return ['quantity' => 1, 'price_data' => array_filter([
            'currency' => strtolower($purchase->currency),
            'unit_amount' => $purchase->amount_cents,
            'tax_behavior' => 'exclusive',
            'product_data' => ['name' => mb_substr($purchase->product_name, 0, 250)],
            'recurring' => $price->isRecurring() ? ['interval' => $price->interval, 'interval_count' => $price->interval_count] : null,
        ])];
    }

    /**
     * The Stripe price for a Kiosk price, made on first use. A Kiosk price's
     * terms never change once paid (a change is a new price), so one Stripe
     * price per Kiosk price per mode is right for good.
     */
    private function price(Price $price, Product $product): string
    {
        $mode = $this->mode();
        $existing = $this->db->table('kiosk_price_refs')->where(['price_id' => $price->id, 'provider' => $this->refKey('stripe'), 'mode' => $mode])->value('external_id');

        if ($existing) {
            return (string) $existing;
        }

        $made = $this->api->call('POST', '/v1/prices', array_filter([
            'currency' => strtolower($price->currency),
            'unit_amount' => $price->amount_cents,
            // 🚨 Stripe Tax needs it set, and it can't change once a price is used.
            'tax_behavior' => 'exclusive',
            'product_data' => ['name' => mb_substr($product->name, 0, 250), 'metadata' => ['kiosk_product_id' => (string) $product->id]],
            'recurring' => $price->isRecurring() ? ['interval' => $price->interval, 'interval_count' => $price->interval_count] : null,
            'metadata' => ['kiosk_price_id' => (string) $price->id],
        ]), 'kiosk-price-'.$mode.'-'.$price->id.($this->payee ? '-a'.$this->payee->id : ''));

        if (! isset($made['id'])) {
            throw new ValidationException(['provider' => $this->translator->trans('ernestdefoe-kiosk.forum.errors.provider_refused')]);
        }

        $this->db->table('kiosk_price_refs')->insertOrIgnore(['price_id' => $price->id, 'provider' => $this->refKey('stripe'), 'mode' => $mode, 'external_id' => $made['id']]);

        return (string) $made['id'];
    }

    public function confirm(Purchase $purchase): void
    {
        $ref = (string) $purchase->provider_ref;

        if ($purchase->status !== Purchase::PENDING || ! str_starts_with($ref, 'cs_')) {
            return;
        }

        $session = $this->api->call('GET', '/v1/checkout/sessions/'.rawurlencode($ref), ['expand' => ['subscription', 'subscription.latest_invoice']], null, $purchase->mode);

        if ($session && ($session['_status'] ?? 500) < 400) {
            $this->applySession($purchase, $session);
        }
    }

    /** @param array<string, mixed> $session */
    private function applySession(Purchase $purchase, array $session): void
    {
        if (($session['status'] ?? '') !== 'complete') {
            return;
        }

        if (($session['mode'] ?? '') === 'subscription') {
            $sub = is_array($session['subscription'] ?? null)
                ? $session['subscription']
                : $this->api->call('GET', '/v1/subscriptions/'.rawurlencode((string) ($session['subscription'] ?? '')), ['expand' => ['latest_invoice']], null, $purchase->mode);

            if (! is_array($sub) || ! isset($sub['id'])) {
                return;
            }

            $purchase->provider_ref = (string) $sub['id'];
            $purchase->save();

            if (is_array($sub['latest_invoice'] ?? null)) {
                $this->invoicePaid($purchase, $sub['latest_invoice'], false);
            }

            $this->applySubscription($purchase, $sub);

            return;
        }

        // One-off: paid, for the amount we asked, in our currency.
        if (($session['payment_status'] ?? '') !== 'paid') {
            return;
        }

        // Before tax: Stripe Tax adds to the total, never to what we charge.
        if ((int) ($session['amount_subtotal'] ?? $session['amount_total'] ?? -1) !== $purchase->amount_cents || strcasecmp((string) ($session['currency'] ?? ''), $purchase->currency) !== 0) {
            $this->log->error('[kiosk] Stripe session amount does not match purchase '.$purchase->number.': '.($session['amount_total'] ?? '?').' '.($session['currency'] ?? '?'));

            return;
        }

        $intent = $session['payment_intent'] ?? null;
        $this->fulfilment->recordPayment($purchase, (string) (is_array($intent) ? $intent['id'] : ($intent ?: $session['id'])), (int) ($session['amount_total'] ?? $purchase->amount_cents), $purchase->currency);

        $now = Carbon::now();
        $this->fulfilment->activate($purchase, $now, $purchase->periodEndFrom($now));
    }

    /** @param array<string, mixed> $sub */
    private function applySubscription(Purchase $purchase, array $sub): void
    {
        $status = (string) ($sub['status'] ?? '');
        $end = isset($sub['current_period_end']) ? Carbon::createFromTimestamp((int) $sub['current_period_end']) : null;
        $start = isset($sub['current_period_start']) ? Carbon::createFromTimestamp((int) $sub['current_period_start']) : Carbon::now();

        if (in_array($status, ['active', 'trialing', 'past_due'], true)) {
            if ($purchase->status === Purchase::EXPIRED) {
                $this->fulfilment->revive($purchase);
            }
            if ($purchase->status === Purchase::PENDING) {
                $this->fulfilment->activate($purchase, $start, $end ?? $purchase->periodEndFrom($start));
                $purchase->refresh();
            } elseif ($end) {
                $this->fulfilment->renew($purchase->refresh(), $end);
            }

            if (! empty($sub['cancel_at_period_end'])) {
                $this->fulfilment->cancelAtPeriodEnd($purchase->refresh());
            }
        } elseif (in_array($status, ['canceled', 'unpaid', 'incomplete_expired'], true)) {
            $this->fulfilment->cancelAtPeriodEnd($purchase);
        }
    }

    /**
     * Money from a subscription invoice. Recorded under its payment intent,
     * the id a refund or dispute names later.
     *
     * @param array<string, mixed> $invoice
     */
    private function invoicePaid(Purchase $purchase, array $invoice, bool $sync = true): void
    {
        $paid = (int) ($invoice['amount_paid'] ?? 0);

        // A trial's first invoice is for nothing: no money moved.
        if (($invoice['status'] ?? '') === 'paid' && $paid > 0) {
            if (strcasecmp((string) ($invoice['currency'] ?? ''), $purchase->currency) !== 0) {
                $this->log->error('[kiosk] Stripe invoice for '.$purchase->number.' is in '.($invoice['currency'] ?? '?'));

                return;
            }

            $intent = $invoice['payment_intent'] ?? null;
            $this->fulfilment->recordPayment($purchase, (string) (is_array($intent) ? $intent['id'] : ($intent ?: $invoice['id'])), $paid, $purchase->currency);
        }

        if ($sync && str_starts_with((string) $purchase->provider_ref, 'sub_')) {
            $this->syncSubscription($purchase);
        }
    }

    private function syncSubscription(Purchase $purchase): void
    {
        $sub = $this->api->call('GET', '/v1/subscriptions/'.rawurlencode((string) $purchase->provider_ref), [], null, $purchase->mode);

        if ($sub && ($sub['_status'] ?? 500) < 400) {
            $this->applySubscription($purchase, $sub);
        }
    }

    public function reconcile(Purchase $purchase): void
    {
        if ($purchase->status === Purchase::PENDING) {
            $this->confirm($purchase);
        } elseif (str_starts_with((string) $purchase->provider_ref, 'sub_')) {
            $this->syncSubscription($purchase);
        }
    }

    /** Stripe swaps the item's price and prorates the difference on the next invoice. */
    public function switchPlan(Purchase $purchase, Price $to, string $returnUrl): ?string
    {
        $sub = $this->api->call('GET', '/v1/subscriptions/'.rawurlencode((string) $purchase->provider_ref), [], null, $purchase->mode);
        $item = $sub['items']['data'][0]['id'] ?? null;

        if (! $item) {
            throw new ValidationException(['purchase' => $this->translator->trans('ernestdefoe-kiosk.forum.errors.provider_unreachable')]);
        }

        $product = $to->product ?? Product::query()->findOrFail($to->product_id);
        $done = $this->api->call('POST', '/v1/subscriptions/'.rawurlencode((string) $purchase->provider_ref), [
            'items' => [['id' => $item, 'price' => $this->price($to, $product)]],
            // Charged now, or not switched: a bigger plan is paid before it's used.
            'proration_behavior' => 'always_invoice',
            'payment_behavior' => 'error_if_incomplete',
            'metadata' => ['kiosk_number' => $purchase->number, 'kiosk_price_id' => (string) $to->id],
        ], 'kiosk-switch-'.$purchase->number.'-'.$to->id, $purchase->mode);

        if (! $done || ($done['_status'] ?? 500) >= 400) {
            throw new ValidationException(['purchase' => $this->translator->trans('ernestdefoe-kiosk.forum.errors.provider_refused')]);
        }

        $this->fulfilment->switchPrice($purchase, $to);

        return null;
    }

    public function verify(array $headers, string $rawBody): bool
    {
        return $this->api->verifyWebhook($headers, $rawBody) !== null;
    }

    public function eventId(array $event): string
    {
        return (string) ($event['id'] ?? '');
    }

    public function eventType(array $event): string
    {
        return (string) ($event['type'] ?? '');
    }

    /**
     * Act on one verified webhook event. Every branch is safe to run twice.
     *
     * @param array<string, mixed> $event
     */
    public function handle(array $event): void
    {
        // 🚨 An event from a member's connected account is acted on only by
        // the copy bound to that account (WebhookController binds it), and the
        // forum's own copy acts only on the forum's own events.
        if ((string) ($event['account'] ?? '') !== ($this->payee?->externalId() ?? '')) {
            return;
        }

        $o = (array) ($event['data']['object'] ?? []);

        // A member finished (or lost) Stripe's sign-up: they can sell when Stripe lets them charge.
        if (($event['type'] ?? '') === 'account.updated' && $this->payee) {
            $this->payee->status = ! empty($o['charges_enabled']) ? Account::CONNECTED : Account::PENDING;
            $this->payee->save();

            return;
        }

        switch ((string) ($event['type'] ?? '')) {
            case 'checkout.session.completed':
            case 'checkout.session.async_payment_succeeded':
                // 🚨 Only the session Kiosk made: a Payment Link or another
                // checkout on the same account can carry our number too.
                if (($purchase = $this->byNumber($o['client_reference_id'] ?? $o['metadata']['kiosk_number'] ?? null))
                    && ($o['id'] ?? null) === $purchase->provider_ref
                    && ($purchase->status === Purchase::PENDING || $this->fulfilment->revive($purchase))) {
                    $this->applySession($purchase, $o);
                }
                break;

            case 'invoice.paid':
                if ($purchase = $this->bySubscription($o['subscription'] ?? null, $o['subscription_details']['metadata']['kiosk_number'] ?? null)) {
                    // The first invoice can beat checkout.session.completed here.
                    if ($purchase->status === Purchase::PENDING) {
                        $this->confirm($purchase);
                        $purchase->refresh();
                    }
                    $this->invoicePaid($purchase, $o);
                }
                break;

            case 'invoice.payment_failed':
                if ($purchase = $this->bySubscription($o['subscription'] ?? null, $o['subscription_details']['metadata']['kiosk_number'] ?? null)) {
                    $this->fulfilment->notify($purchase, 'payment_failed');
                }
                break;

            case 'customer.subscription.updated':
            case 'customer.subscription.deleted':
                if (($purchase = $this->bySubscription($o['id'] ?? null, $o['metadata']['kiosk_number'] ?? null)) && $purchase->status !== Purchase::PENDING) {
                    $this->applySubscription($purchase, $o);
                }
                break;

            case 'charge.refunded':
                $this->refunded((string) ($o['payment_intent'] ?? ''), (int) ($o['amount_refunded'] ?? 0), false);
                break;

            case 'charge.dispute.created':
                $this->refunded((string) ($o['payment_intent'] ?? ''), 0, true);
                break;
        }
    }

    private function refunded(string $intent, int $amount, bool $chargeback): void
    {
        $payment = $intent !== '' ? $this->ownPayments()->where('provider_payment_id', $intent)->first() : null;

        if (! $payment) {
            return;
        }

        $payment->refunded_cents = $chargeback ? $payment->amount_cents : min($payment->amount_cents, $amount);
        if ($chargeback || $payment->refunded_cents >= $payment->amount_cents) {
            $payment->status = $chargeback ? Payment::REVERSED : Payment::REFUNDED;
        }
        $payment->save();

        // A part refund is a goodwill gesture, not the end of the membership.
        if ($payment->status !== Payment::COMPLETED && ($purchase = $payment->purchase) && $purchase->status !== Purchase::REFUNDED) {
            if ($purchase->isRecurring()) {
                $this->endSubscription($purchase);
            }
            $this->fulfilment->end($purchase, Purchase::REFUNDED);
        }
    }

    private function byNumber(mixed $number): ?Purchase
    {
        return is_string($number) && $number !== ''
            ? $this->ownPurchases()->where('number', $number)->first()
            : null;
    }

    private function bySubscription(mixed $subId, mixed $number): ?Purchase
    {
        return (is_string($subId) && $subId !== '' ? $this->ownPurchases()->where('provider_ref', $subId)->first() : null)
            ?? $this->byNumber($number);
    }

    /** Stop renewing; the member keeps what they paid for. */
    public function cancel(Purchase $purchase): bool
    {
        if (! str_starts_with((string) $purchase->provider_ref, 'sub_')) {
            return false;
        }

        $sub = $this->api->call('POST', '/v1/subscriptions/'.rawurlencode((string) $purchase->provider_ref), ['cancel_at_period_end' => true], null, $purchase->mode);

        return $sub !== null && ($sub['_status'] ?? 500) < 300;
    }

    /** End now, after a refund or dispute: nothing more is billed. */
    private function endSubscription(Purchase $purchase): void
    {
        if (str_starts_with((string) $purchase->provider_ref, 'sub_')) {
            $this->api->call('DELETE', '/v1/subscriptions/'.rawurlencode((string) $purchase->provider_ref), [], null, $purchase->mode);
        }
    }

    public function refund(Payment $payment): bool
    {
        $id = $payment->provider_payment_id;
        $param = str_starts_with($id, 'pi_') ? ['payment_intent' => $id] : (str_starts_with($id, 'ch_') ? ['charge' => $id] : null);

        if ($param === null) {
            return false;
        }

        // A member's sale: the forum gives back its cut with the buyer's money.
        if ($this->payee) {
            $param['refund_application_fee'] = true;
        }

        $refund = $this->api->call('POST', '/v1/refunds', $param, 'kiosk-refund-'.$payment->id, $payment->purchase?->mode);

        return $refund !== null && ($refund['_status'] ?? 500) < 300;
    }
}
