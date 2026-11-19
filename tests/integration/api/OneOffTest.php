<?php

namespace Ernestdefoe\Kiosk\Tests\integration\api;

use Carbon\Carbon;
use Ernestdefoe\Kiosk\Tests\integration\KioskTestCase;
use PHPUnit\Framework\Attributes\Test;

/** Buying a one-off and a fixed-term price with PayPal Orders. */
class OneOffTest extends KioskTestCase
{
    #[Test]
    public function only_admins_manage_products(): void
    {
        $response = $this->api('POST', '/kiosk/admin/products', self::BUYER, ['name' => 'X', 'unlocks' => [['type' => 'group', 'targetId' => self::GOLD]], 'prices' => [['kind' => 'one_off', 'amount' => '5', 'currency' => 'USD']]]);
        $this->assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function buying_a_group_grants_it_once_and_records_the_payment(): void
    {
        $product = $this->product([['type' => 'group', 'targetId' => self::GOLD]], [['kind' => 'one_off', 'amount' => '5.00', 'currency' => 'USD']]);
        $this->assertSame('Gold members', $product['unlocks'][0]['label']);

        $store = $this->json($this->api('GET', '/kiosk/store', self::BUYER));
        $this->assertCount(1, $store['data']);
        $this->assertContains(['key' => 'paypal', 'mode' => 'sandbox', 'recurring' => true], $store['meta']['providers']);

        $start = $this->api('POST', '/kiosk/checkout', self::BUYER, ['priceId' => $product['prices'][0]['id'], 'provider' => 'paypal']);
        $this->assertSame(200, $start->getStatusCode(), (string) $start->getBody());
        $checkout = $this->json($start)['data'];
        $this->assertStringContainsString('paypal.com', $checkout['url']);

        // 🚨 The amount sent to PayPal comes from the price row.
        $order = collect($this->paypal->calls)->firstWhere('path', '/v2/checkout/orders');
        $this->assertSame('5.00', $order['body']['purchase_units'][0]['amount']['value']);
        $this->assertSame($checkout['number'], $order['body']['purchase_units'][0]['custom_id']);

        $this->assertFalse($this->inGold(self::BUYER));

        // Back from PayPal, twice (a refresh): one activation, one payment.
        $this->api('POST', '/kiosk/purchases/'.$checkout['number'].'/confirm', self::BUYER);
        $confirmed = $this->json($this->api('POST', '/kiosk/purchases/'.$checkout['number'].'/confirm', self::BUYER))['data'];

        $this->assertSame('active', $confirmed['status']);
        $this->assertNull($confirmed['periodEnd']);
        $this->assertTrue($this->inGold(self::BUYER));
        $this->assertSame(1, $this->db()->table('kiosk_payments')->count());
        $this->assertSame(1, (int) $this->db()->table('kiosk_products')->value('sold_count'));
        $this->assertSame(1, $this->db()->table('kiosk_group_grants')->where('user_id', self::BUYER)->count());

        // The webhook for the same capture changes nothing.
        $this->webhook('PAYMENT.CAPTURE.COMPLETED', ['id' => 'CAP-'.$confirmed['payments'][0]['id'], 'custom_id' => $checkout['number'], 'status' => 'COMPLETED', 'amount' => ['currency_code' => 'USD', 'value' => '5.00']]);
        $this->assertSame(1, (int) $this->db()->table('kiosk_products')->value('sold_count'));
    }

    #[Test]
    public function a_capture_for_the_wrong_amount_grants_nothing(): void
    {
        $product = $this->product([['type' => 'group', 'targetId' => self::GOLD]], [['kind' => 'one_off', 'amount' => '5.00', 'currency' => 'USD']]);
        $this->paypal->captureValue = '0.01';

        $number = $this->json($this->api('POST', '/kiosk/checkout', self::BUYER, ['priceId' => $product['prices'][0]['id'], 'provider' => 'paypal']))['data']['number'];
        $this->api('POST', '/kiosk/purchases/'.$number.'/confirm', self::BUYER);

        $this->assertSame('pending', $this->db()->table('kiosk_purchases')->value('status'));
        $this->assertFalse($this->inGold(self::BUYER));
    }

    #[Test]
    public function someone_elses_purchase_is_not_found(): void
    {
        $product = $this->product([['type' => 'group', 'targetId' => self::GOLD]], [['kind' => 'one_off', 'amount' => '5.00', 'currency' => 'USD']]);
        $number = $this->json($this->api('POST', '/kiosk/checkout', self::BUYER, ['priceId' => $product['prices'][0]['id'], 'provider' => 'paypal']))['data']['number'];

        $this->assertSame(404, $this->api('POST', '/kiosk/purchases/'.$number.'/confirm', 2)->getStatusCode());
    }

    #[Test]
    public function unconfirmed_accounts_and_guests_cannot_check_out(): void
    {
        $product = $this->product([['type' => 'group', 'targetId' => self::GOLD]], [['kind' => 'one_off', 'amount' => '5.00', 'currency' => 'USD']]);
        $price = $product['prices'][0]['id'];

        $this->assertSame(422, $this->api('POST', '/kiosk/checkout', self::UNCONFIRMED, ['priceId' => $price, 'provider' => 'paypal'])->getStatusCode());
        $this->assertGreaterThanOrEqual(400, $this->api('POST', '/kiosk/checkout', null, ['priceId' => $price, 'provider' => 'paypal'])->getStatusCode());
        $this->assertSame(0, $this->db()->table('kiosk_purchases')->count());
    }

    #[Test]
    public function a_fixed_term_ends_on_time_without_the_scheduler_and_leaves_manual_members_alone(): void
    {
        // STAFF is in Gold by hand before buying: theirs to keep.
        $this->db()->table('group_user')->insert(['user_id' => self::STAFF, 'group_id' => self::GOLD]);
        $product = $this->product([['type' => 'group', 'targetId' => self::GOLD]], [['kind' => 'fixed_term', 'termDays' => 30, 'amount' => '5.00', 'currency' => 'USD']]);

        foreach ([self::BUYER, self::STAFF] as $user) {
            $number = $this->json($this->api('POST', '/kiosk/checkout', $user, ['priceId' => $product['prices'][0]['id'], 'provider' => 'paypal']))['data']['number'];
            $this->api('POST', '/kiosk/purchases/'.$number.'/confirm', $user);
        }
        $this->assertTrue($this->inGold(self::BUYER));
        $this->assertSame(0, $this->db()->table('kiosk_group_grants')->where('user_id', self::STAFF)->count());

        Carbon::setTestNow(Carbon::now()->addDays(31));
        // Without a sweep, the group's permissions are gone already…
        $this->assertNotContains(self::GOLD, $this->permissionGroups(self::BUYER));
        // …and the sweep removes the membership Kiosk added, not the manual one.
        $this->app()->getContainer()->make(\Ernestdefoe\Kiosk\Service\Sweeper::class)->run();
        Carbon::setTestNow();

        $this->assertFalse($this->inGold(self::BUYER));
        $this->assertTrue($this->inGold(self::STAFF));
    }

    private function inGold(int $userId): bool
    {
        return $this->db()->table('group_user')->where('user_id', $userId)->where('group_id', self::GOLD)->exists();
    }

    /** @return list<int> */
    private function permissionGroups(int $userId): array
    {
        $this->boot();
        $access = $this->app()->getContainer()->make(\Ernestdefoe\Kiosk\Service\Access::class);
        $access->forget($userId);

        return \Flarum\User\User::query()->find($userId)->permissionGroupIds();
    }
}
