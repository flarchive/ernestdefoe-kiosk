<?php

namespace Ernestdefoe\Kiosk\Tests\integration\api;

use Carbon\Carbon;
use Ernestdefoe\Kiosk\Tests\integration\KioskTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Ways to get paid things without paying, from the pre-release security
 * review. Each one worked before it was fixed.
 */
class AbuseTest extends KioskTestCase
{
    private function inGold(int $u = self::BUYER): bool
    {
        return $this->db()->table('group_user')->where('user_id', $u)->where('group_id', self::GOLD)->exists();
    }

    #[Test]
    public function a_coupon_limit_holds_against_checkouts_started_before_anyone_pays(): void
    {
        $product = $this->product([['type' => 'group', 'targetId' => self::GOLD]], [['kind' => 'one_off', 'amount' => '10.00', 'currency' => 'USD']]);
        $this->assertSame(201, $this->api('POST', '/kiosk/admin/coupons', 1, ['code' => 'half', 'kind' => 'percent', 'value' => 50, 'maxUses' => 1])->getStatusCode());
        $this->paypal->captureValue = '5.00';
        $a = $this->json($this->api('POST', '/kiosk/checkout', self::BUYER, ['priceId' => $product['prices'][0]['id'], 'provider' => 'paypal', 'coupon' => 'half']))['data']['number'];
        $this->assertSame(422, $this->api('POST', '/kiosk/checkout', 2, ['priceId' => $product['prices'][0]['id'], 'provider' => 'paypal', 'coupon' => 'half'])->getStatusCode());
        $this->assertSame(1, (int) $this->db()->table('kiosk_coupons')->value('used_count'));

        // The same member can't use it twice either.
        $this->assertSame(422, $this->api('POST', '/kiosk/checkout', self::BUYER, ['priceId' => $product['prices'][0]['id'], 'provider' => 'paypal', 'coupon' => 'half'])->getStatusCode());

        // An abandoned checkout gives its use back.
        $this->db()->table('kiosk_purchases')->where('number', $a)->update(['created_at' => Carbon::now()->subHours(4)]);
        $this->app()->getContainer()->make(\Ernestdefoe\Kiosk\Service\Sweeper::class)->run();
        $this->assertSame(0, (int) $this->db()->table('kiosk_coupons')->value('used_count'));
        $this->assertSame(200, $this->api('POST', '/kiosk/checkout', 2, ['priceId' => $product['prices'][0]['id'], 'provider' => 'paypal', 'coupon' => 'half'])->getStatusCode());
    }

    #[Test]
    public function moving_a_discussion_into_a_paid_posting_tag_spends_a_credit(): void
    {
        $this->setting('allow_tag_change', 'reply');
        $listing = $this->product([['type' => 'post', 'targetId' => self::LOUNGE]], [['kind' => 'one_off', 'amount' => '3.00', 'currency' => 'USD']]);
        $this->assertSame(201, $this->api('POST', '/kiosk/admin/grants', 1, ['user' => 'buyer', 'productId' => $listing['id'], 'days' => 0])->getStatusCode());

        $ok = 0;
        for ($i = 0; $i < 3; $i++) {
            $this->db()->table('posts')->where('user_id', self::BUYER)->update(['created_at' => '2020-01-01 00:00:00']);
            $r = $this->api('POST', '/discussions', self::BUYER, [
                'type' => 'discussions',
                'attributes' => ['title' => 'Free '.$i, 'content' => 'Hello there, this is a listing.'],
                'relationships' => ['tags' => ['data' => [['type' => 'tags', 'id' => '1']]]],
            ]);
            $this->assertSame(201, $r->getStatusCode(), (string) $r->getBody());
            $id = $this->json($r)['data']['id'];
            $p = $this->api('PATCH', '/discussions/'.$id, self::BUYER, [
                'type' => 'discussions', 'id' => $id,
                'relationships' => ['tags' => ['data' => [['type' => 'tags', 'id' => (string) self::LOUNGE]]]],
            ]);
            if ($p->getStatusCode() === 200) {
                $ok++;
            }
        }
        // One credit, one listing: the second and third moves are refused.
        $this->assertSame(1, $ok);
        $this->assertSame(0, $this->db()->table('kiosk_entitlements')->where('type', 'post')->whereNull('closed_at')->count(), 'the credit was spent');
    }

    #[Test]
    public function a_stripe_session_kiosk_did_not_start_opens_nothing(): void
    {
        $product = $this->product([['type' => 'group', 'targetId' => self::GOLD]], [['kind' => 'recurring', 'amount' => '100.00', 'currency' => 'USD', 'interval' => 'month']]);
        $number = $this->json($this->api('POST', '/kiosk/checkout', self::BUYER, ['priceId' => $product['prices'][0]['id'], 'provider' => 'stripe']))['data']['number'];
        $this->assertSame('cs_test_1', $this->db()->table('kiosk_purchases')->value('provider_ref'));

        // A session Kiosk never made (e.g. a $1/mo Payment Link opened with ?client_reference_id=K-...).
        $this->stripeWebhook('checkout.session.completed', [
            'id' => 'cs_PAYMENT_LINK', 'client_reference_id' => $number, 'status' => 'complete', 'mode' => 'subscription',
            'subscription' => ['id' => 'sub_cheap', 'status' => 'active', 'current_period_start' => time(), 'current_period_end' => strtotime('+1 month'),
                'latest_invoice' => ['id' => 'in_x', 'status' => 'paid', 'amount_paid' => 100, 'currency' => 'usd', 'payment_intent' => 'pi_cheap']],
        ]);
        $this->assertSame('pending', $this->db()->table('kiosk_purchases')->value('status'));
        $this->assertSame('cs_test_1', $this->db()->table('kiosk_purchases')->value('provider_ref'));
        $this->assertFalse($this->inGold());
    }

    #[Test]
    public function a_paypal_subscription_kiosk_did_not_start_opens_nothing(): void
    {
        $product = $this->product([['type' => 'group', 'targetId' => self::GOLD]], [['kind' => 'recurring', 'amount' => '100.00', 'currency' => 'USD', 'interval' => 'month']]);
        $number = $this->json($this->api('POST', '/kiosk/checkout', self::BUYER, ['priceId' => $product['prices'][0]['id'], 'provider' => 'paypal']))['data']['number'];
        $this->webhook('BILLING.SUBSCRIPTION.ACTIVATED', ['id' => 'I-OTHER', 'custom_id' => $number, 'status' => 'ACTIVE', 'plan_id' => 'P-CHEAP',
            'start_time' => gmdate('Y-m-d\TH:i:s\Z'), 'billing_info' => ['next_billing_time' => gmdate('Y-m-d\TH:i:s\Z', strtotime('+1 month'))]]);
        $this->assertSame('pending', $this->db()->table('kiosk_purchases')->value('status'));
        $this->assertFalse($this->inGold());

        // Our own subscription id, on a cheaper plan: still nothing.
        $this->webhook('BILLING.SUBSCRIPTION.ACTIVATED', ['id' => 'I-SUB1', 'custom_id' => $number, 'status' => 'ACTIVE', 'plan_id' => 'P-CHEAP',
            'start_time' => gmdate('Y-m-d\TH:i:s\Z'), 'billing_info' => ['next_billing_time' => gmdate('Y-m-d\TH:i:s\Z', strtotime('+1 month'))]]);
        $this->assertFalse($this->inGold());
    }

    #[Test]
    public function a_free_trial_is_once_per_member(): void
    {
        $product = $this->product([['type' => 'group', 'targetId' => self::GOLD]], [['kind' => 'recurring', 'amount' => '10.00', 'currency' => 'USD', 'interval' => 'month', 'trialDays' => 14]]);
        $this->stripe->subscriptionMode = true;
        $this->stripe->subscriptionStatus = 'trialing';
        $n1 = $this->json($this->api('POST', '/kiosk/checkout', self::BUYER, ['priceId' => $product['prices'][0]['id'], 'provider' => 'stripe']))['data']['number'];
        $this->api('POST', '/kiosk/purchases/'.$n1.'/confirm', self::BUYER);
        $this->assertTrue($this->inGold());
        $this->assertSame(200, $this->api('POST', '/kiosk/purchases/'.$n1.'/cancel', self::BUYER)->getStatusCode());
        $again = $this->api('POST', '/kiosk/checkout', self::BUYER, ['priceId' => $product['prices'][0]['id'], 'provider' => 'stripe']);
        $this->assertSame(200, $again->getStatusCode(), (string) $again->getBody());
        $call = collect($this->stripe->calls)->where('path', '/v1/checkout/sessions')->last();
        $this->assertArrayNotHasKey('trial_period_days', $call['params']['subscription_data'] ?? []);
    }

    #[Test]
    public function paypal_can_t_add_seats_before_they_are_paid_for(): void
    {
        $product = $this->product([['type' => 'group', 'targetId' => self::GOLD]], [
            ['kind' => 'recurring', 'amount' => '5', 'currency' => 'USD', 'interval' => 'month'],
            ['kind' => 'recurring', 'amount' => '50', 'currency' => 'USD', 'interval' => 'month', 'seats' => 10],
        ]);
        $this->db()->table('kiosk_purchases')->insert([
            'number' => 'K-SUBSCRIBE1', 'user_id' => self::BUYER, 'product_id' => $product['id'], 'price_id' => $product['prices'][0]['id'],
            'product_name' => 'Gold', 'amount_cents' => 500, 'currency' => 'USD', 'kind' => 'recurring', 'interval' => 'month',
            'interval_count' => 1, 'provider' => 'paypal', 'mode' => 'sandbox', 'provider_ref' => 'I-SUB1',
            'status' => 'active', 'seats' => 1, 'period_start' => Carbon::now(), 'period_end' => Carbon::now()->addMonth(),
            'created_at' => Carbon::now(), 'updated_at' => Carbon::now(),
        ]);
        $this->db()->table('kiosk_price_refs')->insert(['price_id' => $product['prices'][0]['id'], 'provider' => 'paypal', 'mode' => 'sandbox', 'external_id' => 'P-MONTH']);
        $this->paypal->answers['POST /v1/billing/plans'] = [201, ['id' => 'P-TEAM']];
        $switch = $this->api('POST', '/kiosk/purchases/K-SUBSCRIBE1/switch', self::BUYER, ['priceId' => $product['prices'][1]['id']]);
        $this->assertSame(422, $switch->getStatusCode());
        $this->assertSame(1, (int) $this->db()->table('kiosk_purchases')->value('seats'));
    }

    #[Test]
    public function nobody_is_emailed_a_paid_post_they_haven_t_bought(): void
    {
        $this->product([['type' => 'tag', 'targetId' => self::LOUNGE]], [['kind' => 'one_off', 'amount' => '5.00', 'currency' => 'USD']]);
        $this->boot();
        $this->app();
        $post = \Flarum\Post\Post::query()->find(1);
        $blueprint = new class($post) implements \Flarum\Notification\Blueprint\BlueprintInterface {
            public function __construct(public \Flarum\Post\Post $post) {}
            public function getFromUser(): ?\Flarum\User\User { return null; }
            public function getSubject(): ?\Flarum\Database\AbstractModel { return $this->post->discussion; }
            public function getData(): mixed { return []; }
            public static function getType(): string { return 'test'; }
            public static function getSubjectModel(): string { return \Flarum\Discussion\Discussion::class; }
        };
        $withhold = $this->app()->getContainer()->make(\Ernestdefoe\Kiosk\Listener\WithholdPaidPosts::class);
        $staff = \Flarum\User\User::query()->find(self::STAFF);
        $kept = $withhold($blueprint, [\Flarum\User\User::query()->find(2), $staff]);

        $this->assertSame([self::STAFF], array_map(fn ($u) => (int) $u->id, $kept), 'only someone who can read it is told');
    }

    #[Test]
    public function a_checkout_paid_after_it_expired_takes_its_coupon_use_again(): void
    {
        $product = $this->product([['type' => 'group', 'targetId' => self::GOLD]], [['kind' => 'recurring', 'amount' => '10.00', 'currency' => 'USD', 'interval' => 'month']]);
        $this->assertSame(201, $this->api('POST', '/kiosk/admin/coupons', 1, ['code' => 'half', 'kind' => 'percent', 'value' => 50, 'maxUses' => 1])->getStatusCode());
        $number = $this->json($this->api('POST', '/kiosk/checkout', self::BUYER, ['priceId' => $product['prices'][0]['id'], 'provider' => 'paypal', 'coupon' => 'half']))['data']['number'];

        $this->db()->table('kiosk_purchases')->update(['created_at' => Carbon::now()->subHours(4)]);
        $this->app()->getContainer()->make(\Ernestdefoe\Kiosk\Service\Sweeper::class)->run();
        $this->assertSame('expired', $this->db()->table('kiosk_purchases')->value('status'));
        $this->assertSame(0, (int) $this->db()->table('kiosk_coupons')->value('used_count'));

        // The buyer approves at PayPal late: a real payment, so it opens, and counts.
        $this->api('POST', '/kiosk/purchases/'.$number.'/confirm', self::BUYER);
        $this->assertTrue($this->inGold());
        $this->assertSame('active', $this->db()->table('kiosk_purchases')->value('status'));
        $this->assertSame(1, (int) $this->db()->table('kiosk_coupons')->value('used_count'));
        $this->assertSame(422, $this->api('POST', '/kiosk/checkout', 2, ['priceId' => $product['prices'][0]['id'], 'provider' => 'paypal', 'coupon' => 'half'])->getStatusCode());
    }

    #[Test]
    public function a_late_payment_whose_coupon_ran_out_meanwhile_opens_nothing(): void
    {
        $product = $this->product([['type' => 'group', 'targetId' => self::GOLD]], [['kind' => 'recurring', 'amount' => '10.00', 'currency' => 'USD', 'interval' => 'month']]);
        $this->assertSame(201, $this->api('POST', '/kiosk/admin/coupons', 1, ['code' => 'half', 'kind' => 'percent', 'value' => 50, 'maxUses' => 1])->getStatusCode());
        $number = $this->json($this->api('POST', '/kiosk/checkout', self::BUYER, ['priceId' => $product['prices'][0]['id'], 'provider' => 'paypal', 'coupon' => 'half']))['data']['number'];
        $this->db()->table('kiosk_purchases')->update(['created_at' => Carbon::now()->subHours(4)]);
        $this->app()->getContainer()->make(\Ernestdefoe\Kiosk\Service\Sweeper::class)->run();

        // Someone else uses the freed use first.
        $this->db()->table('kiosk_coupons')->update(['used_count' => 1]);

        $this->api('POST', '/kiosk/purchases/'.$number.'/confirm', self::BUYER);
        $this->assertFalse($this->inGold());
        $this->assertSame('expired', $this->db()->table('kiosk_purchases')->value('status'));
    }

    #[Test]
    public function search_can_t_find_words_in_the_hidden_part_of_a_paid_post(): void
    {
        $this->product([['type' => 'tag', 'targetId' => self::LOUNGE]], [['kind' => 'one_off', 'amount' => '5.00', 'currency' => 'USD']]);
        $found = fn (?int $user, string $q) => count($this->json($this->api('GET', '/discussions', $user, [], ['filter' => ['q' => $q]]))['data'] ?? []);

        // MySQL's fulltext index can't see a test's uncommitted rows, so the
        // "is found" checks run on SQLite and PostgreSQL only.
        $sees = in_array($this->db()->getDriverName(), ['sqlite', 'pgsql'], true);

        foreach ([null, 2] as $user) {
            $this->assertSame(0, $found($user, 'FINALE-SECRET'), 'a non-buyer can search the hidden text');
            if ($sees) {
                $this->assertSame(1, $found($user, 'Members only'), 'the title is still searchable');
            }
        }
        // Moderators with "read paid content" read it all, so they can search it.
        if ($sees) {
            $this->assertSame(1, $found(self::STAFF, 'FINALE-SECRET'));
        }
        // And the teaser still shows outside search.
        $this->assertStringContainsString('Behind the rope', (string) $this->api('GET', '/discussions/1', 2)->getBody());
    }
}
