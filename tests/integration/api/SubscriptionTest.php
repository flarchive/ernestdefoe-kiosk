<?php

namespace Ernestdefoe\Kiosk\Tests\integration\api;

use Carbon\Carbon;
use Ernestdefoe\Kiosk\Tests\integration\KioskTestCase;
use PHPUnit\Framework\Attributes\Test;

/** Recurring prices as PayPal Subscriptions, driven by webhooks. */
class SubscriptionTest extends KioskTestCase
{
    /** @return array{0: array<string, mixed>, 1: string} the product and the purchase number */
    private function subscribe(int $user = self::BUYER): array
    {
        $product = $this->product([['type' => 'group', 'targetId' => self::GOLD]], [['kind' => 'recurring', 'interval' => 'month', 'amount' => '5.00', 'currency' => 'USD']]);
        $start = $this->api('POST', '/kiosk/checkout', $user, ['priceId' => $product['prices'][0]['id'], 'provider' => 'paypal']);
        $this->assertSame(200, $start->getStatusCode(), (string) $start->getBody());

        return [$product, $this->json($start)['data']['number']];
    }

    #[Test]
    public function the_plan_is_made_once_from_the_price(): void
    {
        [$product] = $this->subscribe();
        $this->api('POST', '/kiosk/checkout', 2, ['priceId' => $product['prices'][0]['id'], 'provider' => 'paypal']);

        $this->assertSame(1, count(array_filter($this->paypal->asked(), fn ($a) => $a === 'POST /v1/billing/plans')));
        $plan = collect($this->paypal->calls)->firstWhere('path', '/v1/billing/plans');
        $this->assertSame('5.00', $plan['body']['billing_cycles'][0]['pricing_scheme']['fixed_price']['value']);
        $this->assertSame('MONTH', $plan['body']['billing_cycles'][0]['frequency']['interval_unit']);
    }

    #[Test]
    public function activation_renewal_and_cancellation(): void
    {
        [, $number] = $this->subscribe();
        $next = Carbon::now()->addMonth()->startOfSecond();
        $this->paypal->nextBilling = $next->toIso8601ZuluString();

        $this->assertSame(200, $this->webhook('BILLING.SUBSCRIPTION.ACTIVATED', ['id' => 'I-SUB1', 'custom_id' => $number, 'plan_id' => 'P-PLAN1', 'status' => 'ACTIVE', 'start_time' => Carbon::now()->toIso8601ZuluString(), 'billing_info' => ['next_billing_time' => $next->toIso8601ZuluString()]], 'EV-1')->getStatusCode());

        $purchase = $this->db()->table('kiosk_purchases')->first();
        $this->assertSame('active', $purchase->status);
        $this->assertSame($next->getTimestamp(), Carbon::parse($purchase->period_end)->getTimestamp());
        $this->assertTrue($this->db()->table('group_user')->where('user_id', self::BUYER)->where('group_id', self::GOLD)->exists());

        // Recurring access carries the grace days past the period.
        $ends = Carbon::parse($this->db()->table('kiosk_entitlements')->value('ends_at'));
        $this->assertSame($next->copy()->addDays(3)->getTimestamp(), $ends->getTimestamp());

        // A renewal: the sale, then PayPal's new next billing time.
        $later = $next->copy()->addMonth();
        $this->paypal->nextBilling = $later->toIso8601ZuluString();
        $sale = ['id' => 'SALE-2', 'billing_agreement_id' => 'I-SUB1', 'amount' => ['total' => '5.00', 'currency' => 'USD'], 'state' => 'completed'];
        $this->webhook('PAYMENT.SALE.COMPLETED', $sale, 'EV-2');
        // Delivered again: the event log answers "already have it".
        $this->webhook('PAYMENT.SALE.COMPLETED', $sale, 'EV-2');

        $this->assertSame(1, $this->db()->table('kiosk_payments')->count());
        $this->assertSame($later->getTimestamp(), Carbon::parse($this->db()->table('kiosk_purchases')->value('period_end'))->getTimestamp());
        $this->assertSame(1, $this->db()->table('kiosk_webhook_events')->count() - 1);

        // The member cancels: no more renewals, access to the end of the period, no grace.
        $cancel = $this->json($this->api('POST', '/kiosk/purchases/'.$number.'/cancel', self::BUYER))['data'];
        $this->assertSame('canceled', $cancel['status']);
        $this->assertContains('POST /v1/billing/subscriptions/I-SUB1/cancel', $this->paypal->asked());
        $this->assertSame($later->getTimestamp(), Carbon::parse($this->db()->table('kiosk_entitlements')->value('ends_at'))->getTimestamp());
    }

    #[Test]
    public function a_second_subscription_to_the_same_product_is_refused(): void
    {
        [$product, $number] = $this->subscribe();
        $this->webhook('BILLING.SUBSCRIPTION.ACTIVATED', ['id' => 'I-SUB1', 'custom_id' => $number, 'plan_id' => 'P-PLAN1', 'status' => 'ACTIVE']);

        $again = $this->api('POST', '/kiosk/checkout', self::BUYER, ['priceId' => $product['prices'][0]['id'], 'provider' => 'paypal']);
        $this->assertSame(422, $again->getStatusCode());
    }

    #[Test]
    public function an_unverified_webhook_is_refused_and_not_stored(): void
    {
        [, $number] = $this->subscribe();
        $this->paypal->verification = 'FAILURE';

        $this->assertSame(400, $this->webhook('BILLING.SUBSCRIPTION.ACTIVATED', ['id' => 'I-SUB1', 'custom_id' => $number, 'plan_id' => 'P-PLAN1', 'status' => 'ACTIVE'])->getStatusCode());
        $this->assertSame('pending', $this->db()->table('kiosk_purchases')->value('status'));
        $this->assertSame(0, $this->db()->table('kiosk_webhook_events')->count());
    }

    #[Test]
    public function a_refunded_sale_ends_access_now(): void
    {
        [, $number] = $this->subscribe();
        $this->webhook('BILLING.SUBSCRIPTION.ACTIVATED', ['id' => 'I-SUB1', 'custom_id' => $number, 'plan_id' => 'P-PLAN1', 'status' => 'ACTIVE']);
        $this->webhook('PAYMENT.SALE.COMPLETED', ['id' => 'SALE-1', 'billing_agreement_id' => 'I-SUB1', 'amount' => ['total' => '5.00', 'currency' => 'USD']]);

        $this->webhook('PAYMENT.SALE.REFUNDED', ['id' => 'REF-1', 'sale_id' => 'SALE-1', 'amount' => ['total' => '5.00', 'currency' => 'USD']]);

        $this->assertSame('refunded', $this->db()->table('kiosk_purchases')->value('status'));
        $this->assertSame('refunded', $this->db()->table('kiosk_payments')->value('status'));
        $this->assertFalse($this->db()->table('group_user')->where('user_id', self::BUYER)->where('group_id', self::GOLD)->exists());
    }
}
