<?php

namespace Ernestdefoe\Kiosk\Tests\integration\api;

use Carbon\Carbon;
use Ernestdefoe\Kiosk\Tests\integration\KioskTestCase;
use PHPUnit\Framework\Attributes\Test;

/** Tags, overlapping products, grants, price edits and the admin's secrets. */
class AccessTest extends KioskTestCase
{
    #[Test]
    public function a_hidden_paid_tag_is_restricted_and_buyers_see_it(): void
    {
        $this->assertTrue($this->seesLounge(2));

        $product = $this->product([['type' => 'tag', 'targetId' => self::LOUNGE, 'teaser' => false]], [['kind' => 'one_off', 'amount' => '5.00', 'currency' => 'USD']]);
        $this->assertSame('Lounge', $product['unlocks'][0]['label']);
        $this->assertTrue((bool) $this->db()->table('tags')->where('id', self::LOUNGE)->value('is_restricted'));
        $this->assertSame(1, $this->db()->table('kiosk_tag_groups')->count());

        // Members without it no longer see it; staff keep seeing it.
        $this->assertFalse($this->seesLounge(2));
        $this->assertFalse($this->seesLounge(self::BUYER));
        $this->assertTrue($this->seesLounge(self::STAFF));
        $this->assertTrue($this->db()->table('group_permission')->where('group_id', 4)->where('permission', 'tag2.discussion.hide')->exists());

        $number = $this->json($this->api('POST', '/kiosk/checkout', self::BUYER, ['priceId' => $product['prices'][0]['id'], 'provider' => 'paypal']))['data']['number'];
        $this->api('POST', '/kiosk/purchases/'.$number.'/confirm', self::BUYER);

        $this->assertTrue($this->seesLounge(self::BUYER));
        $this->assertFalse($this->seesLounge(2));
        // Nothing written to group_user: the buyer's group is for permissions only.
        $this->assertFalse($this->db()->table('group_user')->where('user_id', self::BUYER)->exists());
    }

    #[Test]
    public function the_store_lookup_finds_hidden_products_for_their_item(): void
    {
        $this->product([['type' => 'tag', 'targetId' => self::LOUNGE]], [['kind' => 'one_off', 'amount' => '5.00', 'currency' => 'USD']], ['hidden' => true]);

        $this->assertCount(0, $this->json($this->api('GET', '/kiosk/store', self::BUYER))['data']);
        $this->assertCount(1, $this->json($this->api('GET', '/kiosk/store', self::BUYER, [], ['type' => 'tag', 'id' => self::LOUNGE]))['data']);
    }

    #[Test]
    public function ending_one_of_two_products_that_grant_the_same_group_keeps_it(): void
    {
        $a = $this->product([['type' => 'group', 'targetId' => self::GOLD]], [['kind' => 'one_off', 'amount' => '5.00', 'currency' => 'USD']]);
        $b = $this->product([['type' => 'group', 'targetId' => self::GOLD]], [['kind' => 'one_off', 'amount' => '9.00', 'currency' => 'USD']]);

        $first = $this->json($this->api('POST', '/kiosk/admin/grants', 1, ['user' => 'buyer', 'productId' => $a['id'], 'days' => 0]))['data'];
        $this->json($this->api('POST', '/kiosk/admin/grants', 1, ['user' => 'buyer', 'productId' => $b['id'], 'days' => 0]));
        $this->assertSame('grant', $first['provider']);

        $this->api('POST', '/kiosk/admin/purchases/'.$first['id'].'/end', 1);
        $this->assertTrue($this->db()->table('group_user')->where('user_id', self::BUYER)->where('group_id', self::GOLD)->exists());
    }

    #[Test]
    public function a_price_somebody_paid_is_archived_not_edited(): void
    {
        $product = $this->product([['type' => 'group', 'targetId' => self::GOLD]], [['kind' => 'one_off', 'amount' => '5.00', 'currency' => 'USD']]);
        $priceId = $product['prices'][0]['id'];

        // Not yet bought: edited in place.
        $edited = $this->json($this->api('PATCH', '/kiosk/admin/products/'.$product['id'], 1, ['prices' => [['id' => $priceId, 'kind' => 'one_off', 'amount' => '6.00', 'currency' => 'USD']]]))['data'];
        $this->assertSame($priceId, $edited['prices'][0]['id']);
        $this->assertSame(600, $edited['prices'][0]['amountCents']);

        $this->api('POST', '/kiosk/checkout', self::BUYER, ['priceId' => $priceId, 'provider' => 'paypal']);

        $again = $this->json($this->api('PATCH', '/kiosk/admin/products/'.$product['id'], 1, ['prices' => [['id' => $priceId, 'kind' => 'one_off', 'amount' => '7.00', 'currency' => 'USD']]]))['data'];
        $this->assertNotSame($priceId, $again['prices'][0]['id']);
        $this->assertSame(600, (int) $this->db()->table('kiosk_prices')->where('id', $priceId)->value('amount_cents'));
        $this->assertNotNull($this->db()->table('kiosk_prices')->where('id', $priceId)->value('archived_at'));
    }

    #[Test]
    public function the_admin_page_never_receives_the_secret(): void
    {
        $page = (string) $this->send($this->request('GET', '/admin', ['authenticatedAs' => 1]))->getBody();

        $this->assertStringContainsString('ernestdefoe-kiosk.paypal_sandbox_client_id', $page);
        $this->assertStringNotContainsString('ernestdefoe-kiosk.paypal_sandbox_secret', $page);
    }

    #[Test]
    public function a_grant_for_days_lapses_and_the_sweep_marks_it_ended(): void
    {
        $product = $this->product([['type' => 'group', 'targetId' => self::GOLD]], [['kind' => 'one_off', 'amount' => '5.00', 'currency' => 'USD']]);
        $this->api('POST', '/kiosk/admin/grants', 1, ['user' => 'buyer', 'productId' => $product['id'], 'days' => 7]);

        Carbon::setTestNow(Carbon::now()->addDays(11));
        $out = $this->app()->getContainer()->make(\Ernestdefoe\Kiosk\Service\Sweeper::class)->run();
        Carbon::setTestNow();

        $this->assertSame(1, $out['closed']);
        $this->assertSame('ended', $this->db()->table('kiosk_purchases')->value('status'));
        $this->assertFalse($this->db()->table('group_user')->where('user_id', self::BUYER)->where('group_id', self::GOLD)->exists());
    }

    #[Test]
    public function the_forum_lists_paid_tags_a_member_cannot_see_yet(): void
    {
        $product = $this->product([['type' => 'tag', 'targetId' => self::LOUNGE, 'teaser' => false]], [['kind' => 'one_off', 'amount' => '5.00', 'currency' => 'USD']]);

        $locked = fn (?int $user) => array_column($this->json($this->api('GET', '/', $user))['data']['attributes']['kioskLockedTags'] ?? [], 'slug');
        $this->assertSame(['lounge'], $locked(self::BUYER));
        $this->assertSame([], $locked(self::STAFF));

        $this->api('POST', '/kiosk/admin/grants', 1, ['user' => 'buyer', 'productId' => $product['id'], 'days' => 0]);
        $this->assertSame([], $locked(self::BUYER));
    }

    #[Test]
    public function a_shared_link_to_a_paid_tag_opens_instead_of_not_found(): void
    {
        $this->product([['type' => 'tag', 'targetId' => self::LOUNGE, 'teaser' => false]], [['kind' => 'one_off', 'amount' => '5.00', 'currency' => 'USD']]);

        $page = $this->send($this->request('GET', '/t/lounge', ['authenticatedAs' => self::BUYER]));
        $this->assertSame(200, $page->getStatusCode());
        $this->assertStringContainsString('<title>Lounge', (string) $page->getBody());
        // Its discussions are never sent to someone without access.
        $this->assertStringNotContainsString('Members only', (string) $page->getBody());

        // A tag that is not for sale still answers "not found".
        $this->db()->table('tags')->insert(['id' => 3, 'name' => 'Secret', 'slug' => 'secret', 'is_restricted' => true]);
        $this->assertSame(404, $this->send($this->request('GET', '/t/secret', ['authenticatedAs' => self::BUYER]))->getStatusCode());
    }
}
