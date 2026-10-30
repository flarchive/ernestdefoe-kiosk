<?php

namespace Ernestdefoe\Kiosk\Tests\integration\api;

use Carbon\Carbon;
use Ernestdefoe\Kiosk\Tests\integration\KioskTestCase;
use PHPUnit\Framework\Attributes\Test;

/** Coupons, gifts, team seats and switching plan. */
class CouponGiftSeatTest extends KioskTestCase
{
    private function inGold(int $userId): bool
    {
        return $this->db()->table('group_user')->where('user_id', $userId)->where('group_id', self::GOLD)->exists();
    }

    /** @param array<string, mixed> $data */
    private function coupon(array $data): array
    {
        $response = $this->api('POST', '/kiosk/admin/coupons', 1, $data);
        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return $this->json($response)['data'];
    }

    private function gold(array $price = ['kind' => 'one_off', 'amount' => '10.00', 'currency' => 'USD']): array
    {
        return $this->product([['type' => 'group', 'targetId' => self::GOLD]], [$price]);
    }

    #[Test]
    public function a_percent_coupon_lowers_what_paypal_is_asked_for_and_is_counted_once(): void
    {
        $product = $this->gold();
        $this->coupon(['code' => 'half', 'kind' => 'percent', 'value' => 50, 'maxUses' => 1]);

        $preview = $this->json($this->api('POST', '/kiosk/coupons/check', self::BUYER, ['priceId' => $product['prices'][0]['id'], 'code' => 'HALF']));
        $this->assertSame(500, $preview['data']['amountCents']);

        $this->paypal->captureValue = '5.00';
        $number = $this->json($this->api('POST', '/kiosk/checkout', self::BUYER, ['priceId' => $product['prices'][0]['id'], 'provider' => 'paypal', 'coupon' => 'half']))['data']['number'];
        $order = collect($this->paypal->calls)->firstWhere('path', '/v2/checkout/orders');
        $this->assertSame('5.00', $order['body']['purchase_units'][0]['amount']['value']);

        $this->api('POST', '/kiosk/purchases/'.$number.'/confirm', self::BUYER);
        $this->assertTrue($this->inGold(self::BUYER));
        $this->assertSame(1, (int) $this->db()->table('kiosk_coupons')->value('used_count'));

        // Used up.
        $again = $this->api('POST', '/kiosk/checkout', 2, ['priceId' => $product['prices'][0]['id'], 'provider' => 'paypal', 'coupon' => 'half']);
        $this->assertSame(422, $again->getStatusCode());
    }

    #[Test]
    public function a_free_coupon_opens_a_one_off_without_paypal_but_never_a_subscription(): void
    {
        $product = $this->gold();
        $this->coupon(['code' => 'FREE', 'kind' => 'percent', 'value' => 100]);

        $start = $this->api('POST', '/kiosk/checkout', self::BUYER, ['priceId' => $product['prices'][0]['id'], 'provider' => 'paypal', 'coupon' => 'free']);
        $this->assertSame(200, $start->getStatusCode(), (string) $start->getBody());
        $this->assertTrue($this->inGold(self::BUYER));
        $this->assertSame([], array_filter($this->paypal->asked(), fn ($c) => str_contains($c, '/v2/checkout/orders')));

        $monthly = $this->product([['type' => 'group', 'targetId' => self::GOLD]], [['kind' => 'recurring', 'amount' => '5', 'currency' => 'USD', 'interval' => 'month']]);
        $refused = $this->api('POST', '/kiosk/checkout', 2, ['priceId' => $monthly['prices'][0]['id'], 'provider' => 'paypal', 'coupon' => 'FREE']);
        $this->assertSame(422, $refused->getStatusCode());
    }

    #[Test]
    public function a_coupon_on_a_subscription_overrides_the_plan_price(): void
    {
        $monthly = $this->product([['type' => 'group', 'targetId' => self::GOLD]], [['kind' => 'recurring', 'amount' => '10', 'currency' => 'USD', 'interval' => 'month']]);
        $this->coupon(['code' => 'TWO-OFF', 'kind' => 'amount', 'value' => '2', 'currency' => 'USD']);

        $this->api('POST', '/kiosk/checkout', self::BUYER, ['priceId' => $monthly['prices'][0]['id'], 'provider' => 'paypal', 'coupon' => 'two-off']);
        $sub = collect($this->paypal->calls)->firstWhere('path', '/v1/billing/subscriptions');
        $this->assertSame('8.00', $sub['body']['plan']['billing_cycles'][0]['pricing_scheme']['fixed_price']['value']);
    }

    #[Test]
    public function guessing_codes_is_cut_off(): void
    {
        $product = $this->gold();
        for ($i = 0; $i < 10; $i++) {
            $this->api('POST', '/kiosk/coupons/check', self::BUYER, ['priceId' => $product['prices'][0]['id'], 'code' => 'NOPE'.$i]);
        }
        $this->coupon(['code' => 'REAL', 'kind' => 'percent', 'value' => 10]);

        $response = $this->api('POST', '/kiosk/coupons/check', self::BUYER, ['priceId' => $product['prices'][0]['id'], 'code' => 'REAL']);
        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('too_many', (string) $response->getBody());
    }

    #[Test]
    public function a_gift_belongs_to_the_recipient_and_the_giver_follows_it_but_cannot_manage_it(): void
    {
        $product = $this->gold(['kind' => 'fixed_term', 'amount' => '10.00', 'currency' => 'USD', 'termDays' => 30]);
        $this->paypal->captureValue = '10.00';

        $start = $this->api('POST', '/kiosk/checkout', self::BUYER, ['priceId' => $product['prices'][0]['id'], 'provider' => 'paypal', 'giftTo' => 'normal']);
        $this->assertSame(200, $start->getStatusCode(), (string) $start->getBody());
        $number = $this->json($start)['data']['number'];

        // The giver comes back from PayPal and confirms it.
        $this->assertSame(200, $this->api('POST', '/kiosk/purchases/'.$number.'/confirm', self::BUYER)->getStatusCode());
        $this->assertTrue($this->inGold(2));
        $this->assertFalse($this->inGold(self::BUYER));

        $row = $this->db()->table('kiosk_purchases')->first();
        $this->assertSame(2, (int) $row->user_id);
        $this->assertSame(self::BUYER, (int) $row->gifted_by);

        $mine = $this->json($this->api('GET', '/kiosk/purchases', 2))['data'][0];
        $this->assertSame('owner', $mine['role']);
        $this->assertSame('buyer', $mine['giftedBy']['username']);
        $given = $this->json($this->api('GET', '/kiosk/purchases', self::BUYER))['data'][0];
        $this->assertSame('giver', $given['role']);

        $this->assertSame(404, $this->api('POST', '/kiosk/purchases/'.$number.'/cancel', self::BUYER)->getStatusCode());
        $this->assertSame(['gift'], $this->db()->table('notifications')->where('user_id', 2)->pluck('data')->map(fn ($d) => json_decode($d, true)['kind'])->all());
    }

    #[Test]
    public function subscriptions_and_yourself_cannot_be_gifted(): void
    {
        $monthly = $this->product([['type' => 'group', 'targetId' => self::GOLD]], [['kind' => 'recurring', 'amount' => '5', 'currency' => 'USD', 'interval' => 'month']]);
        $this->assertSame(422, $this->api('POST', '/kiosk/checkout', self::BUYER, ['priceId' => $monthly['prices'][0]['id'], 'provider' => 'paypal', 'giftTo' => 'normal'])->getStatusCode());

        $once = $this->gold();
        $this->assertSame(422, $this->api('POST', '/kiosk/checkout', self::BUYER, ['priceId' => $once['prices'][0]['id'], 'provider' => 'paypal', 'giftTo' => 'buyer'])->getStatusCode());
        $this->assertSame(422, $this->api('POST', '/kiosk/checkout', self::BUYER, ['priceId' => $once['prices'][0]['id'], 'provider' => 'paypal', 'giftTo' => 'nobody'])->getStatusCode());
    }

    #[Test]
    public function team_seats_share_access_and_end_with_the_seat(): void
    {
        $product = $this->gold(['kind' => 'one_off', 'amount' => '30.00', 'currency' => 'USD', 'seats' => 2]);
        $this->assertSame(2, $product['prices'][0]['seats']);
        $this->paypal->captureValue = '30.00';

        $number = $this->json($this->api('POST', '/kiosk/checkout', self::BUYER, ['priceId' => $product['prices'][0]['id'], 'provider' => 'paypal']))['data']['number'];
        $this->api('POST', '/kiosk/purchases/'.$number.'/confirm', self::BUYER);

        $added = $this->api('POST', '/kiosk/purchases/'.$number.'/seats', self::BUYER, ['username' => 'normal']);
        $this->assertSame(200, $added->getStatusCode(), (string) $added->getBody());
        $this->assertTrue($this->inGold(2));
        $this->assertSame('normal', $this->json($added)['data']['seatHolders'][0]['username']);
        // Only the buyer's unlocks are listed on the purchase.
        $this->assertCount(1, $this->json($added)['data']['unlocks']);

        // Two seats, both taken (the buyer holds one).
        $this->assertSame(422, $this->api('POST', '/kiosk/purchases/'.$number.'/seats', self::BUYER, ['username' => 'staff'])->getStatusCode());
        // A seat holder sees it, but can't hand out seats.
        $this->assertSame('seat', $this->json($this->api('GET', '/kiosk/purchases', 2))['data'][0]['role']);
        $this->assertSame(404, $this->api('POST', '/kiosk/purchases/'.$number.'/seats', 2, ['username' => 'staff'])->getStatusCode());

        $this->api('DELETE', '/kiosk/purchases/'.$number.'/seats/2', self::BUYER);
        $this->assertFalse($this->inGold(2));
        $this->assertTrue($this->inGold(self::BUYER));

        // When the purchase ends, every seat holder loses access with it.
        $this->api('POST', '/kiosk/purchases/'.$number.'/seats', self::BUYER, ['username' => 'staff']);
        $this->assertTrue($this->inGold(self::STAFF));
        $this->api('POST', '/kiosk/admin/purchases/'.$this->db()->table('kiosk_purchases')->value('id').'/end', 1);
        $this->assertFalse($this->inGold(self::STAFF));
        $this->assertFalse($this->inGold(self::BUYER));
    }

    #[Test]
    public function stripe_switches_plan_at_once_and_charges_the_difference(): void
    {
        $product = $this->product([['type' => 'group', 'targetId' => self::GOLD]], [
            ['kind' => 'recurring', 'amount' => '5', 'currency' => 'USD', 'interval' => 'month'],
            ['kind' => 'recurring', 'amount' => '50', 'currency' => 'USD', 'interval' => 'year'],
        ]);
        $number = $this->activeSubscription($product, 'stripe', 'sub_1');

        $switched = $this->api('POST', '/kiosk/purchases/'.$number.'/switch', self::BUYER, ['priceId' => $product['prices'][1]['id']]);
        $this->assertSame(200, $switched->getStatusCode(), (string) $switched->getBody());
        $this->assertSame(5000, $this->json($switched)['data']['amountCents']);
        $this->assertSame('year', $this->json($switched)['data']['interval']);

        $update = collect($this->stripe->calls)->last(fn ($c) => $c['method'] === 'POST' && $c['path'] === '/v1/subscriptions/sub_1');
        // Charged now, so a bigger plan is paid for before it's used.
        $this->assertSame('always_invoice', $update['params']['proration_behavior']);
        $this->assertSame('error_if_incomplete', $update['params']['payment_behavior']);
        $this->assertSame('si_1', $update['params']['items'][0]['id']);
    }

    #[Test]
    public function paypal_switches_plan_once_the_buyer_approves_it(): void
    {
        $product = $this->product([['type' => 'group', 'targetId' => self::GOLD]], [
            ['kind' => 'recurring', 'amount' => '5', 'currency' => 'USD', 'interval' => 'month'],
            ['kind' => 'recurring', 'amount' => '50', 'currency' => 'USD', 'interval' => 'year'],
        ]);
        $number = $this->activeSubscription($product, 'paypal', 'I-SUB1');
        $this->db()->table('kiosk_price_refs')->insert(['price_id' => $product['prices'][0]['id'], 'provider' => 'paypal', 'mode' => 'sandbox', 'external_id' => 'P-MONTH']);
        $this->paypal->answers['POST /v1/billing/plans'] = [201, ['id' => 'P-YEAR']];

        $response = $this->json($this->api('POST', '/kiosk/purchases/'.$number.'/switch', self::BUYER, ['priceId' => $product['prices'][1]['id']]));
        $this->assertStringContainsString('paypal.com', $response['data']['url']);
        // The year plan reuses the month plan's catalog product, so PayPal can revise between them.
        $this->assertNotContains('POST /v1/catalogs/products', $this->paypal->asked());
        $this->assertSame(500, (int) $this->db()->table('kiosk_purchases')->value('amount_cents'));

        // Approved: PayPal now reports the year plan.
        $this->paypal->planId = 'P-YEAR';
        $this->api('POST', '/kiosk/purchases/'.$number.'/confirm', self::BUYER);
        $this->assertSame(5000, (int) $this->db()->table('kiosk_purchases')->value('amount_cents'));
        $this->assertSame((int) $product['prices'][1]['id'], (int) $this->db()->table('kiosk_purchases')->value('price_id'));
    }

    private function activeSubscription(array $product, string $provider, string $ref): string
    {
        $price = $product['prices'][0];
        $this->db()->table('kiosk_purchases')->insert([
            'number' => 'K-SUBSCRIBE1', 'user_id' => self::BUYER, 'product_id' => $product['id'], 'price_id' => $price['id'],
            'product_name' => 'Gold', 'amount_cents' => 500, 'currency' => 'USD', 'kind' => 'recurring', 'interval' => 'month',
            'interval_count' => 1, 'provider' => $provider, 'mode' => $provider === 'paypal' ? 'sandbox' : 'test', 'provider_ref' => $ref,
            'status' => 'active', 'seats' => 1, 'period_start' => Carbon::now(), 'period_end' => Carbon::now()->addMonth(),
            'created_at' => Carbon::now(), 'updated_at' => Carbon::now(),
        ]);

        return 'K-SUBSCRIBE1';
    }
}
