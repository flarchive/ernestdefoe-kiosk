<?php

namespace Ernestdefoe\Kiosk\Tests\integration\api;

use Carbon\Carbon;
use Ernestdefoe\Kiosk\Tests\integration\KioskTestCase;
use PHPUnit\Framework\Attributes\Test;

/** Stripe Checkout for one-offs and Stripe Billing for subscriptions. */
class StripeTest extends KioskTestCase
{
    private function buy(string $kind = 'one_off'): string
    {
        $price = ['kind' => $kind, 'amount' => '5.00', 'currency' => 'USD'] + ($kind === 'recurring' ? ['interval' => 'month'] : []);
        $product = $this->product([['type' => 'group', 'targetId' => self::GOLD]], [$price]);
        $start = $this->api('POST', '/kiosk/checkout', self::BUYER, ['priceId' => $product['prices'][0]['id'], 'provider' => 'stripe']);
        $this->assertSame(200, $start->getStatusCode(), (string) $start->getBody());

        return $this->json($start)['data']['number'];
    }

    private function inGold(): bool
    {
        return $this->db()->table('group_user')->where('user_id', self::BUYER)->where('group_id', self::GOLD)->exists();
    }

    #[Test]
    public function connect_replaces_the_old_endpoint_and_keeps_the_new_secret(): void
    {
        $this->setting('ernestdefoe-kiosk.stripe_test_secret', '');
        $response = $this->api('POST', '/kiosk/admin/stripe', 1, ['key' => 'sk_test_new']);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $data = $this->json($response)['data'];

        $this->assertTrue($data['ok']);
        $this->assertSame('test', $data['mode']);
        $this->assertSame('…_new', $data['status']['test']['keyHint']);
        $this->assertContains('DELETE /v1/webhook_endpoints/we_old', $this->stripe->asked());
        $this->assertSame('whsec_new', $this->db()->table('settings')->where('key', 'ernestdefoe-kiosk.stripe_test_webhook_secret')->value('value'));

        $bad = $this->api('POST', '/kiosk/admin/stripe', 1, ['key' => 'pk_test_publishable']);
        $this->assertSame(422, $bad->getStatusCode());
    }

    #[Test]
    public function a_one_off_is_paid_through_checkout_and_confirmed_on_return(): void
    {
        $number = $this->buy();

        $session = collect($this->stripe->calls)->firstWhere('path', '/v1/checkout/sessions');
        $this->assertSame('payment', $session['params']['mode']);
        $this->assertSame($number, $session['params']['client_reference_id']);
        $this->assertSame('2024-06-20', $session['headers']['Stripe-Version'][0]);
        $price = collect($this->stripe->calls)->firstWhere('path', '/v1/prices');
        $this->assertSame('500', $price['params']['unit_amount']);

        $this->api('POST', '/kiosk/purchases/'.$number.'/confirm', self::BUYER);
        $this->assertSame('active', $this->db()->table('kiosk_purchases')->value('status'));
        $this->assertSame('pi_1', $this->db()->table('kiosk_payments')->value('provider_payment_id'));
        $this->assertTrue($this->inGold());

        // The same news by webhook changes nothing.
        $this->stripeWebhook('checkout.session.completed', $this->stripe->session() + ['client_reference_id' => $number]);
        $this->assertSame(1, $this->db()->table('kiosk_payments')->count());
    }

    #[Test]
    public function a_session_for_the_wrong_amount_grants_nothing(): void
    {
        $number = $this->buy();
        $this->stripe->amountTotal = 1;

        $this->api('POST', '/kiosk/purchases/'.$number.'/confirm', self::BUYER);
        $this->assertSame('pending', $this->db()->table('kiosk_purchases')->value('status'));
        $this->assertFalse($this->inGold());
    }

    #[Test]
    public function forged_and_replayed_webhooks_are_refused(): void
    {
        $number = $this->buy();
        $body = $this->stripe->session() + ['client_reference_id' => $number];

        $this->assertSame(400, $this->stripeWebhook('checkout.session.completed', $body, null, 'whsec_wrong')->getStatusCode());
        $this->assertSame(400, $this->stripeWebhook('checkout.session.completed', $body, null, 'whsec_test', time() - 3600)->getStatusCode());
        $this->assertSame('pending', $this->db()->table('kiosk_purchases')->value('status'));
        $this->assertSame(0, $this->db()->table('kiosk_webhook_events')->count());
    }

    #[Test]
    public function a_subscription_activates_renews_cancels_and_refunds(): void
    {
        $number = $this->buy('recurring');
        $this->stripe->subscriptionMode = true;

        $session = collect($this->stripe->calls)->firstWhere('path', '/v1/checkout/sessions');
        $this->assertSame('subscription', $session['params']['mode']);
        $this->assertSame($number, $session['params']['subscription_data']['metadata']['kiosk_number']);

        $this->stripeWebhook('checkout.session.completed', ['id' => 'cs_test_1', 'client_reference_id' => $number, 'status' => 'complete', 'mode' => 'subscription', 'subscription' => 'sub_1']);
        // Its first invoice arrives too, and is the same payment.
        $this->stripeWebhook('invoice.paid', $this->stripe->invoice());

        $purchase = $this->db()->table('kiosk_purchases')->first();
        $this->assertSame('active', $purchase->status);
        $this->assertSame('sub_1', $purchase->provider_ref);
        $this->assertSame(1, $this->db()->table('kiosk_payments')->count());
        $this->assertTrue($this->inGold());

        // A renewal: new payment, period moves on.
        $later = Carbon::now()->addMonths(2)->startOfSecond();
        $this->stripe->periodEnd = $later->getTimestamp();
        $this->stripeWebhook('invoice.paid', $this->stripe->invoice('pi_sub_2'));
        $this->assertSame(2, $this->db()->table('kiosk_payments')->count());
        $this->assertSame($later->getTimestamp(), Carbon::parse($this->db()->table('kiosk_purchases')->value('period_end'))->getTimestamp());

        // The member stops it renewing.
        $cancel = $this->json($this->api('POST', '/kiosk/purchases/'.$number.'/cancel', self::BUYER))['data'];
        $this->assertSame('canceled', $cancel['status']);
        $call = collect($this->stripe->calls)->first(fn ($c) => $c['method'] === 'POST' && $c['path'] === '/v1/subscriptions/sub_1');
        $this->assertSame('true', $call['params']['cancel_at_period_end']);

        // Refunded in Stripe's dashboard: access ends now.
        $this->stripeWebhook('charge.refunded', ['id' => 'ch_2', 'payment_intent' => 'pi_sub_2', 'amount_refunded' => 500]);
        $this->assertSame('refunded', $this->db()->table('kiosk_purchases')->value('status'));
        $this->assertFalse($this->inGold());
    }

    #[Test]
    public function the_store_offers_both_providers_and_the_checkout_asks_which(): void
    {
        $this->product([['type' => 'group', 'targetId' => self::GOLD]], [['kind' => 'one_off', 'amount' => '5.00', 'currency' => 'USD']]);

        $providers = array_column($this->json($this->api('GET', '/kiosk/store', self::BUYER))['meta']['providers'], 'key');
        $this->assertSame(['paypal', 'stripe'], $providers);
    }
}
