<?php

namespace Ernestdefoe\Kiosk\Tests\integration\api;

use Ernestdefoe\Kiosk\Tests\integration\KioskTestCase;
use PHPUnit\Framework\Attributes\Test;

/** Paid actions in a tag: posting, bumping, tipping. */
class CreditsTest extends KioskTestCase
{
    private function start(int $user, string $title = 'A listing'): int
    {
        // Past Flarum's own flood control (one post per few seconds).
        $this->db()->table('posts')->where('user_id', $user)->update(['created_at' => '2020-01-01 00:00:00']);

        return $this->api('POST', '/discussions', $user, [
            'type' => 'discussions',
            'attributes' => ['title' => $title, 'content' => 'Hello there, this is a listing.'],
            'relationships' => ['tags' => ['data' => [['type' => 'tags', 'id' => (string) self::LOUNGE]]]],
        ])->getStatusCode();
    }

    private function grant(int $productId, int $days = 0): void
    {
        $this->assertSame(201, $this->api('POST', '/kiosk/admin/grants', 1, ['user' => 'buyer', 'productId' => $productId, 'days' => $days])->getStatusCode());
    }

    #[Test]
    public function a_listing_credit_starts_exactly_one_discussion(): void
    {
        $listing = $this->product([['type' => 'post', 'targetId' => self::LOUNGE]], [['kind' => 'one_off', 'amount' => '3.00', 'currency' => 'USD']]);

        $this->assertSame([self::LOUNGE], $this->json($this->api('GET', '/', self::BUYER))['data']['attributes']['kioskPostTags']);
        $this->assertSame(403, $this->start(self::BUYER), 'No credit, no posting');

        $this->grant($listing['id']);
        $this->assertSame(201, $this->start(self::BUYER, 'First'));
        $this->assertSame(403, $this->start(self::BUYER, 'Second'), 'The credit was spent');
    }

    #[Test]
    public function a_posting_pass_is_not_spent(): void
    {
        $pass = $this->product([['type' => 'post', 'targetId' => self::LOUNGE]], [['kind' => 'fixed_term', 'termDays' => 30, 'amount' => '9.00', 'currency' => 'USD']]);
        $this->grant($pass['id'], 30);

        $this->assertSame(201, $this->start(self::BUYER, 'One'));
        $this->assertSame(201, $this->start(self::BUYER, 'Two'));
    }

    #[Test]
    public function a_bump_credit_bumps_once(): void
    {
        $bump = $this->product([['type' => 'bump', 'targetId' => self::LOUNGE]], [['kind' => 'one_off', 'amount' => '1.00', 'currency' => 'USD']]);
        $this->db()->table('discussions')->where('id', 1)->update(['user_id' => self::BUYER, 'last_posted_at' => '2020-01-01 00:00:00']);
        $this->grant($bump['id']);

        $this->assertSame(1, $this->json($this->api('GET', '/discussions/1', self::BUYER))['data']['attributes']['kioskBumps']);
        $this->assertSame(200, $this->api('POST', '/kiosk/discussions/1/bump', self::BUYER)->getStatusCode());
        $this->assertNotSame('2020-01-01 00:00:00', (string) $this->db()->table('discussions')->where('id', 1)->value('last_posted_at'));
        $this->assertSame(422, $this->api('POST', '/kiosk/discussions/1/bump', self::BUYER)->getStatusCode(), 'Spent');
        $this->assertSame(403, $this->api('POST', '/kiosk/discussions/1/bump', 2)->getStatusCode(), 'Only your own');
    }

    #[Test]
    public function a_tip_is_pay_what_you_want_for_a_discussion_in_its_tag(): void
    {
        $tip = $this->product([['type' => 'tip', 'targetId' => self::LOUNGE]], [['kind' => 'custom', 'amount' => '2.00', 'currency' => 'USD']]);
        $priceId = $tip['prices'][0]['id'];

        $this->assertSame(422, $this->api('POST', '/kiosk/checkout', self::BUYER, ['priceId' => $priceId, 'provider' => 'paypal', 'amount' => '1.00', 'context' => ['type' => 'discussion', 'id' => 1]])->getStatusCode(), 'Below the minimum');
        $this->assertSame(422, $this->api('POST', '/kiosk/checkout', self::BUYER, ['priceId' => $priceId, 'provider' => 'paypal', 'amount' => '7.00'])->getStatusCode(), 'A tip backs a discussion');

        $start = $this->json($this->api('POST', '/kiosk/checkout', self::BUYER, ['priceId' => $priceId, 'provider' => 'paypal', 'amount' => '7.00', 'context' => ['type' => 'discussion', 'id' => 1]]));
        $this->paypal->captureValue = '7.00';
        $this->api('POST', '/kiosk/purchases/'.$start['data']['number'].'/confirm', self::BUYER);

        $tips = $this->json($this->api('GET', '/kiosk/discussions/1/tips', 2))['data'];
        $this->assertTrue($tips['canTip']);
        $this->assertSame([['currency' => 'USD', 'totalCents' => 700, 'backers' => 1]], $tips['raised']);
    }
}
