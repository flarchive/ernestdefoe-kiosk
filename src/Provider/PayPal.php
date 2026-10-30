<?php

namespace Ernestdefoe\Kiosk\Provider;

use Carbon\Carbon;
use Ernestdefoe\Kiosk\Model\Money;
use Ernestdefoe\Kiosk\Model\Payment;
use Ernestdefoe\Kiosk\Model\Price;
use Ernestdefoe\Kiosk\Model\Product;
use Ernestdefoe\Kiosk\Model\Purchase;
use Ernestdefoe\Kiosk\Service\Fulfilment;
use Flarum\Foundation\ValidationException;
use Flarum\Locale\TranslatorInterface;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Database\ConnectionInterface;
use Psr\Log\LoggerInterface;

/**
 * PayPal for Kiosk.
 *
 * One-off and fixed-term prices are Orders v2: the buyer approves, we capture.
 * Recurring prices are PayPal Subscriptions, available to every business
 * account — unlike charging a saved payment method, which PayPal only allows
 * on accounts it has approved for it. The billing plan behind a price is made
 * the first time anybody subscribes at it.
 *
 * 🚨 The browser is never believed. The return page asks PayPal what happened
 * (confirm), webhooks are verified with PayPal before they are read, and every
 * amount PayPal reports is compared with the amount we asked for.
 */
class PayPal implements Provider, WebhookProvider
{
    public function __construct(
        private PayPalClient $api,
        private Fulfilment $fulfilment,
        private ConnectionInterface $db,
        private SettingsRepositoryInterface $settings,
        private TranslatorInterface $translator,
        private LoggerInterface $log,
    ) {
    }

    public function key(): string
    {
        return 'paypal';
    }

    public function isReady(): bool
    {
        return $this->api->isConnected();
    }

    public function mode(): string
    {
        return $this->api->mode();
    }

    public function client(): PayPalClient
    {
        return $this->api;
    }

    public function supports(Price $price): bool
    {
        if (! $price->isRecurring()) {
            return true;
        }

        // PayPal's longest billing cycles.
        $max = ['day' => 365, 'week' => 52, 'month' => 12, 'year' => 1][$price->interval] ?? 0;

        return $price->interval_count <= $max && $price->trial_days <= 365;
    }

    public function checkout(Purchase $purchase, Price $price, Product $product, string $returnUrl, string $cancelUrl): string
    {
        return $price->isRecurring()
            ? $this->startSubscription($purchase, $price, $product, $returnUrl, $cancelUrl)
            : $this->startOrder($purchase, $returnUrl, $cancelUrl);
    }

    private function startOrder(Purchase $purchase, string $returnUrl, string $cancelUrl): string
    {
        $body = $this->api->call('POST', '/v2/checkout/orders', [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => $purchase->number,
                // Ties the capture and every webhook back to the purchase
                // without trusting anything the browser sends back.
                'custom_id' => $purchase->number,
                'description' => mb_substr($purchase->product_name, 0, 127),
                'amount' => ['currency_code' => $purchase->currency, 'value' => Money::toDecimal($purchase->amount_cents, $purchase->currency)],
            ]],
            'payment_source' => ['paypal' => ['experience_context' => [
                'brand_name' => $this->brand(),
                'shipping_preference' => 'NO_SHIPPING',
                'user_action' => 'PAY_NOW',
                'return_url' => $returnUrl,
                'cancel_url' => $cancelUrl,
            ]]],
        ], 'kiosk-'.$purchase->number);

        return $this->started($purchase, $body);
    }

    private function startSubscription(Purchase $purchase, Price $price, Product $product, string $returnUrl, string $cancelUrl): string
    {
        $plan = $this->plan($price, $product);

        $body = $this->api->call('POST', '/v1/billing/subscriptions', array_filter([
            'plan_id' => $plan,
            'custom_id' => $purchase->number,
            // A coupon: the same plan at the discounted price, every cycle.
            'plan' => $purchase->amount_cents === $price->amount_cents ? null : ['billing_cycles' => [[
                'sequence' => $price->trial_days > 0 ? 2 : 1,
                'pricing_scheme' => ['fixed_price' => ['value' => Money::toDecimal($purchase->amount_cents, $purchase->currency), 'currency_code' => $purchase->currency]],
            ]]],
            'application_context' => [
                'brand_name' => $this->brand(),
                'shipping_preference' => 'NO_SHIPPING',
                'user_action' => 'SUBSCRIBE_NOW',
                'return_url' => $returnUrl,
                'cancel_url' => $cancelUrl,
            ],
        ], fn ($v) => $v !== null), 'kiosk-'.$purchase->number);

        return $this->started($purchase, $body);
    }

    /** @param array<string, mixed>|null $body */
    private function started(Purchase $purchase, ?array $body): string
    {
        $link = $body ? PayPalClient::approveLink($body) : null;

        if (! $body || ! isset($body['id']) || ! $link) {
            throw new ValidationException(['provider' => $this->translator->trans(
                $body === null && ! $this->api->rejected ? 'ernestdefoe-kiosk.forum.errors.provider_unreachable' : 'ernestdefoe-kiosk.forum.errors.provider_refused'
            )]);
        }

        $purchase->provider_ref = (string) $body['id'];
        $purchase->save();

        return $link;
    }

    /**
     * The PayPal billing plan for a recurring price, made on first use.
     *
     * A price's terms never change once anyone has paid it (a change is a new
     * price), so one plan per price per mode is right for good.
     */
    private function plan(Price $price, Product $product): string
    {
        $mode = $this->mode();
        // A member who had the trial gets the same plan without it.
        $ref = $price->trial_days === 0 && (int) $price->getOriginal('trial_days') > 0 ? 'paypal_notrial' : 'paypal';
        $existing = $this->db->table('kiosk_price_refs')->where(['price_id' => $price->id, 'provider' => $ref, 'mode' => $mode])->value('external_id');

        if ($existing) {
            return (string) $existing;
        }

        // 🚨 PayPal only revises between plans of ONE catalog product, so a
        // product's prices share the catalog product its first plan made.
        $sibling = $this->db->table('kiosk_price_refs')
            ->join('kiosk_prices', 'kiosk_prices.id', '=', 'kiosk_price_refs.price_id')
            ->where(['kiosk_prices.product_id' => $product->id, 'kiosk_price_refs.mode' => $mode])->whereIn('kiosk_price_refs.provider', ['paypal', 'paypal_notrial'])
            ->value('kiosk_price_refs.external_id');
        $known = $sibling ? ($this->api->call('GET', '/v1/billing/plans/'.rawurlencode((string) $sibling))['product_id'] ?? null) : null;

        $catalog = $known ? ['id' => $known] : $this->api->call('POST', '/v1/catalogs/products', [
            'name' => mb_substr($product->name, 0, 127),
            'type' => 'DIGITAL',
        ], 'kiosk-product-'.$mode.'-'.$price->id);

        if (! isset($catalog['id'])) {
            throw new ValidationException(['provider' => $this->translator->trans('ernestdefoe-kiosk.forum.errors.provider_refused')]);
        }

        $cycles = [];
        if ($price->trial_days > 0) {
            $cycles[] = [
                'frequency' => ['interval_unit' => 'DAY', 'interval_count' => $price->trial_days],
                'tenure_type' => 'TRIAL',
                'sequence' => 1,
                'total_cycles' => 1,
            ];
        }
        $cycles[] = [
            'frequency' => ['interval_unit' => strtoupper((string) $price->interval), 'interval_count' => $price->interval_count],
            'tenure_type' => 'REGULAR',
            'sequence' => count($cycles) + 1,
            'total_cycles' => 0,
            'pricing_scheme' => ['fixed_price' => ['value' => Money::toDecimal($price->amount_cents, $price->currency), 'currency_code' => strtoupper($price->currency)]],
        ];

        $plan = $this->api->call('POST', '/v1/billing/plans', [
            'product_id' => $catalog['id'],
            'name' => mb_substr($product->name, 0, 127),
            'status' => 'ACTIVE',
            'billing_cycles' => $cycles,
            'payment_preferences' => [
                'auto_bill_outstanding' => true,
                'payment_failure_threshold' => 3,
            ],
        ], 'kiosk-plan-'.$mode.'-'.$price->id.($ref === 'paypal' ? '' : '-notrial'));

        if (! isset($plan['id'])) {
            throw new ValidationException(['provider' => $this->translator->trans('ernestdefoe-kiosk.forum.errors.provider_refused')]);
        }

        $this->db->table('kiosk_price_refs')->insertOrIgnore(['price_id' => $price->id, 'provider' => $ref, 'mode' => $mode, 'external_id' => $plan['id']]);

        return (string) $plan['id'];
    }

    public function confirm(Purchase $purchase): void
    {
        if ($purchase->status !== Purchase::PENDING || ! $purchase->provider_ref) {
            return;
        }

        $purchase->isRecurring() ? $this->syncSubscription($purchase) : $this->capture($purchase);
    }

    public function reconcile(Purchase $purchase): void
    {
        if (! $purchase->provider_ref) {
            return;
        }

        if ($purchase->isRecurring()) {
            $this->syncSubscription($purchase);
        } elseif ($purchase->status === Purchase::PENDING) {
            $this->capture($purchase);
        }
    }

    /**
     * Take the money for an approved order.
     *
     * 🚨 ORDER_ALREADY_CAPTURED is neither failure nor proof of payment: the
     * webhook and the return page both capture, and the first capture may be
     * PENDING or DENIED. Read the order and judge it like a fresh capture.
     */
    private function capture(Purchase $purchase): void
    {
        $body = $this->api->call('POST', '/v2/checkout/orders/'.rawurlencode((string) $purchase->provider_ref).'/capture', new \stdClass(), 'kiosk-capture-'.$purchase->number, $purchase->mode);

        if ($body === null) {
            return;
        }

        $issue = $body['details'][0]['issue'] ?? null;

        if ($issue === 'ORDER_ALREADY_CAPTURED' || $issue === 'ORDER_NOT_APPROVED') {
            $body = $this->api->call('GET', '/v2/checkout/orders/'.rawurlencode((string) $purchase->provider_ref), null, null, $purchase->mode);
        }

        foreach ($body['purchase_units'] ?? [] as $unit) {
            foreach ($unit['payments']['captures'] ?? [] as $capture) {
                $this->captureCompleted($purchase, $capture);
            }
        }
    }

    /** @param array<string, mixed> $capture */
    private function captureCompleted(Purchase $purchase, array $capture): void
    {
        if (($capture['status'] ?? '') !== 'COMPLETED' || empty($capture['id'])) {
            return;
        }

        if (! $this->amountMatches($capture['amount'] ?? null, $purchase)) {
            $this->log->error('[kiosk] PayPal capture amount does not match purchase '.$purchase->number.': '.json_encode($capture['amount'] ?? null));

            return;
        }

        $this->fulfilment->recordPayment($purchase, (string) $capture['id'], $purchase->amount_cents, $purchase->currency);

        $now = Carbon::now();
        $this->fulfilment->activate($purchase, $now, $purchase->periodEndFrom($now));
    }

    /** Bring a subscription purchase in line with what PayPal says about it. */
    private function syncSubscription(Purchase $purchase): void
    {
        $sub = $this->api->call('GET', '/v1/billing/subscriptions/'.rawurlencode((string) $purchase->provider_ref), null, null, $purchase->mode);

        if (! $sub || ($sub['_status'] ?? 500) >= 400) {
            return;
        }

        $this->applySubscription($purchase, $sub);
    }

    /** @param array<string, mixed> $sub */
    private function applySubscription(Purchase $purchase, array $sub): void
    {
        $status = (string) ($sub['status'] ?? '');
        $next = isset($sub['billing_info']['next_billing_time']) ? Carbon::parse($sub['billing_info']['next_billing_time']) : null;

        if ($status === 'ACTIVE' && $purchase->status === Purchase::EXPIRED) {
            $this->fulfilment->revive($purchase);
        }

        if ($status === 'ACTIVE' && $purchase->status !== Purchase::PENDING && ($to = $this->priceOfPlan($purchase, $sub['plan_id'] ?? null))) {
            $this->fulfilment->switchPrice($purchase, $to);
        }

        if ($status === 'ACTIVE') {
            $start = isset($sub['start_time']) ? Carbon::parse($sub['start_time']) : Carbon::now();
            // No next billing time means PayPal has not scheduled one yet;
            // give the first period by our own terms rather than none.
            $end = $next ?? $purchase->periodEndFrom($start);

            if ($purchase->status === Purchase::PENDING) {
                // The plan must be this purchase's own price, not a cheaper one.
                if (($sub['id'] ?? null) !== $purchase->provider_ref || $this->priceOfPlan($purchase, $sub['plan_id'] ?? null)?->id !== $purchase->price_id) {
                    $this->log->error('[kiosk] PayPal subscription '.($sub['id'] ?? '?').' is not the one started for '.$purchase->number);

                    return;
                }
                $this->fulfilment->activate($purchase, $start, $end);
            } elseif ($end) {
                $this->fulfilment->renew($purchase->refresh(), $end);
            }
        } elseif (in_array($status, ['CANCELLED', 'EXPIRED'], true)) {
            $this->fulfilment->cancelAtPeriodEnd($purchase);
        }
    }

    /** @param mixed $amount PayPal's {value, currency_code} */
    private function amountMatches(mixed $amount, Purchase $purchase): bool
    {
        return is_array($amount)
            && Money::toMinor($amount['value'] ?? null, $purchase->currency) === $purchase->amount_cents
            && strcasecmp((string) ($amount['currency_code'] ?? ''), $purchase->currency) === 0;
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
        return (string) ($event['event_type'] ?? '');
    }

    /**
     * Act on one verified webhook event. Every branch is safe to run twice:
     * the event log stops most repeats, and the rest meet a status check or
     * the unique payment index.
     *
     * @param array<string, mixed> $event
     */
    public function handle(array $event): void
    {
        $type = (string) ($event['event_type'] ?? '');
        $r = (array) ($event['resource'] ?? []);

        switch ($type) {
            case 'CHECKOUT.ORDER.APPROVED':
                // The buyer approved and closed the tab before coming back:
                // capture now rather than leave them paid-for and locked out.
                if ($purchase = $this->byNumber($r['purchase_units'][0]['custom_id'] ?? null)) {
                    $this->confirm($purchase);
                }
                break;

            case 'PAYMENT.CAPTURE.COMPLETED':
                // Only a capture of the order Kiosk created (its "up" link).
                if (($purchase = $this->byNumber($r['custom_id'] ?? null)) && $this->orderOf($r) === $purchase->provider_ref) {
                    $this->captureCompleted($purchase, $r);
                }
                break;

            case 'PAYMENT.CAPTURE.REFUNDED':
            case 'PAYMENT.CAPTURE.REVERSED':
                $this->refunded($this->upId($r) ?? (string) ($r['id'] ?? ''), $type === 'PAYMENT.CAPTURE.REVERSED', $r);
                break;

            case 'BILLING.SUBSCRIPTION.ACTIVATED':
            case 'BILLING.SUBSCRIPTION.UPDATED':
            case 'BILLING.SUBSCRIPTION.CANCELLED':
            case 'BILLING.SUBSCRIPTION.EXPIRED':
                if ($purchase = $this->bySubscription($r)) {
                    $this->applySubscription($purchase, $r);
                }
                break;

            case 'BILLING.SUBSCRIPTION.SUSPENDED':
            case 'BILLING.SUBSCRIPTION.PAYMENT.FAILED':
                // Access runs on through the grace days; tell them to fix it.
                if ($purchase = $this->bySubscription($r)) {
                    $this->fulfilment->notify($purchase, 'payment_failed');
                }
                break;

            case 'PAYMENT.SALE.COMPLETED':
                $this->saleCompleted($r);
                break;

            case 'PAYMENT.SALE.REFUNDED':
            case 'PAYMENT.SALE.REVERSED':
                $this->refunded((string) ($r['sale_id'] ?? $r['id'] ?? ''), $type === 'PAYMENT.SALE.REVERSED', $r);
                break;
        }
    }

    /** A subscription payment: the first one or a renewal. @param array<string, mixed> $sale */
    private function saleCompleted(array $sale): void
    {
        $subId = (string) ($sale['billing_agreement_id'] ?? '');
        $purchase = $subId !== '' ? Purchase::query()->where('provider', 'paypal')->where('provider_ref', $subId)->first() : null;

        if (! $purchase || empty($sale['id'])) {
            return;
        }

        $amount = $sale['amount'] ?? [];
        $minor = Money::toMinor($amount['total'] ?? null, (string) ($amount['currency'] ?? $purchase->currency));

        if ($minor === null || strcasecmp((string) ($amount['currency'] ?? ''), $purchase->currency) !== 0) {
            $this->log->error('[kiosk] PayPal sale for '.$purchase->number.' has an unexpected amount: '.json_encode($amount));

            return;
        }

        $this->fulfilment->recordPayment($purchase, (string) $sale['id'], $minor, $purchase->currency);

        // The sale says money arrived, not until when: ask PayPal for the new
        // next billing time.
        $this->syncSubscription($purchase);
    }

    /** @param array<string, mixed> $resource */
    private function refunded(string $paymentId, bool $chargeback, array $resource): void
    {
        $payment = $paymentId !== '' ? Payment::query()->where('provider', 'paypal')->where('provider_payment_id', $paymentId)->first() : null;

        if (! $payment) {
            return;
        }

        $refunded = Money::toMinor($resource['amount']['value'] ?? $resource['amount']['total'] ?? null, $payment->currency);
        $full = $chargeback || $refunded === null || $refunded >= $payment->amount_cents;

        $payment->refunded_cents = min($payment->amount_cents, $payment->refunded_cents + ($refunded ?? $payment->amount_cents));
        if ($full || $payment->refunded_cents >= $payment->amount_cents) {
            $payment->status = $chargeback ? Payment::REVERSED : Payment::REFUNDED;
        }
        $payment->save();

        // A part refund is a goodwill gesture, not the end of the membership.
        if ($payment->status !== Payment::COMPLETED && ($purchase = $payment->purchase) && $purchase->status !== Purchase::REFUNDED) {
            if ($purchase->isRecurring() && $purchase->provider_ref) {
                $this->cancel($purchase);
            }
            $this->fulfilment->end($purchase, Purchase::REFUNDED);
        }
    }

    /** The capture a refund belongs to, from its "up" link. @param array<string, mixed> $r */
    /** @param array<string, mixed> $r the order a capture belongs to, from its "up" link */
    private function orderOf(array $r): ?string
    {
        foreach ($r['links'] ?? [] as $link) {
            if (($link['rel'] ?? '') === 'up' && preg_match('#/orders/([^/?]+)#', (string) ($link['href'] ?? ''), $m)) {
                return $m[1];
            }
        }

        return null;
    }

    private function upId(array $r): ?string
    {
        foreach ($r['links'] ?? [] as $link) {
            if (($link['rel'] ?? '') === 'up' && preg_match('#/captures/([^/?]+)#', (string) ($link['href'] ?? ''), $m)) {
                return $m[1];
            }
        }

        return null;
    }

    private function byNumber(mixed $number): ?Purchase
    {
        return is_string($number) && $number !== ''
            ? Purchase::query()->where('number', $number)->where('provider', 'paypal')->first()
            : null;
    }

    /** @param array<string, mixed> $r */
    private function bySubscription(array $r): ?Purchase
    {
        // 🚨 By the subscription id Kiosk was given, never by custom_id alone:
        // anyone can make a subscription on this app carrying our number.
        return isset($r['id']) ? Purchase::query()->where('provider', 'paypal')->where('provider_ref', (string) $r['id'])->first() : null;
    }

    public function cancel(Purchase $purchase): bool
    {
        if (! $purchase->isRecurring() || ! $purchase->provider_ref) {
            return false;
        }

        $body = $this->api->call('POST', '/v1/billing/subscriptions/'.rawurlencode($purchase->provider_ref).'/cancel', [
            'reason' => mb_substr((string) $this->translator->trans('ernestdefoe-kiosk.lib.cancel_reason'), 0, 127),
        ], null, $purchase->mode);

        // 204 = cancelled; 422 SUBSCRIPTION_STATUS_INVALID = it already was.
        return $body !== null && (($body['_status'] ?? 500) < 300 || ($body['details'][0]['issue'] ?? '') === 'SUBSCRIPTION_STATUS_INVALID');
    }

    public function refund(Payment $payment): bool
    {
        $purchase = $payment->purchase;
        $sale = $purchase && $purchase->isRecurring();

        $body = $this->api->call(
            'POST',
            $sale ? '/v1/payments/sale/'.rawurlencode($payment->provider_payment_id).'/refund' : '/v2/payments/captures/'.rawurlencode($payment->provider_payment_id).'/refund',
            new \stdClass(),
            'kiosk-refund-'.$payment->id,
            $purchase?->mode,
        );

        return $body !== null && ($body['_status'] ?? 500) < 300;
    }

    /**
     * PayPal revises the subscription to the new plan once the buyer approves
     * it there. The change lands when PayPal reports the new plan (the return
     * page or BILLING.SUBSCRIPTION.UPDATED), never before.
     */
    public function switchPlan(Purchase $purchase, Price $to, string $returnUrl): ?string
    {
        $body = $this->api->call('POST', '/v1/billing/subscriptions/'.rawurlencode((string) $purchase->provider_ref).'/revise', [
            'plan_id' => $this->plan($to, $to->product ?? Product::query()->findOrFail($to->product_id)),
            'application_context' => [
                'brand_name' => $this->brand(),
                'shipping_preference' => 'NO_SHIPPING',
                'user_action' => 'CONTINUE',
                'return_url' => $returnUrl,
                'cancel_url' => $returnUrl,
            ],
        ], null, $purchase->mode);

        $link = $body ? PayPalClient::approveLink($body) : null;

        if (! $link) {
            throw new ValidationException(['purchase' => $this->translator->trans($body === null ? 'ernestdefoe-kiosk.forum.errors.provider_unreachable' : 'ernestdefoe-kiosk.forum.errors.provider_refused')]);
        }

        return $link;
    }

    /** The Kiosk price a PayPal plan id was made for, in this purchase's mode. */
    private function priceOfPlan(Purchase $purchase, mixed $planId): ?Price
    {
        $id = $planId ? $this->db->table('kiosk_price_refs')->whereIn('provider', ['paypal', 'paypal_notrial'])->where(['mode' => $purchase->mode, 'external_id' => (string) $planId])->value('price_id') : null;

        return $id ? Price::query()->find($id) : null;
    }

    private function brand(): string
    {
        return mb_substr((string) $this->settings->get('forum_title'), 0, 127);
    }
}
