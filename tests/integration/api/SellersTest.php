<?php

namespace Ernestdefoe\Kiosk\Tests\integration\api;

use Ernestdefoe\Kiosk\Extend\UnlockType;
use Ernestdefoe\Kiosk\Tests\integration\Fixtures\TestItemUnlock;
use Ernestdefoe\Kiosk\Tests\integration\KioskTestCase;
use Laminas\Diactoros\StreamFactory;
use PHPUnit\Framework\Attributes\Test;

/**
 * Members who sell (Kiosk Sellers): member 2 sells, BUYER buys, admin reviews.
 * Item 201 belongs to member 2 (TestItemUnlock: item N is member N/100's).
 */
class SellersTest extends KioskTestCase
{
    private const SELLER = 2;

    protected function setUp(): void
    {
        parent::setUp();
        TestItemUnlock::$prepared = [];
        $this->extend(new UnlockType('test-item', TestItemUnlock::class));
        $this->setting('ernestdefoe-kiosk.sellers', '1');
        $this->prepareDatabase(['group_permission' => [['group_id' => 3, 'permission' => 'kiosk.sell']]]);
    }

    private function connectPayPal(): array
    {
        $response = $this->api('POST', '/kiosk/selling/accounts/paypal', self::SELLER, ['clientId' => 'seller-client-ABCD', 'secret' => 'seller-secret']);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return $this->json($response)['data'];
    }

    private function listing(array $extra = []): array
    {
        $response = $this->api('POST', '/kiosk/selling/listings', self::SELLER, $extra + [
            'name' => 'My mod pack',
            'unlocks' => [['type' => 'test-item', 'targetId' => 201]],
            'prices' => [['kind' => 'one_off', 'amount' => '8.00', 'currency' => 'USD']],
        ]);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return $this->json($response)['data']['listings'][0];
    }

    private function approve(int $id): void
    {
        $this->assertSame(200, $this->api('POST', '/kiosk/admin/listings/'.$id, 1, ['action' => 'approve'])->getStatusCode());
    }

    #[Test]
    public function selling_is_off_until_the_admin_turns_it_on_and_needs_the_permission(): void
    {
        $this->setting('ernestdefoe-kiosk.sellers', '');
        $this->assertSame(403, $this->api('GET', '/kiosk/selling', self::SELLER)->getStatusCode());
        $this->assertFalse($this->json($this->api('GET', '/'))['data']['attributes']['kioskCanSell'] ?? false);
    }

    #[Test]
    public function a_paypal_account_is_sealed_and_gets_its_own_webhook(): void
    {
        $data = $this->connectPayPal();
        $this->assertSame(['key' => 'paypal', 'status' => 'connected', 'hint' => '…ABCD', 'byEmail' => false], collect($data['providers'])->firstWhere('key', 'paypal'));

        // 🚨 The secret is never in the database in the clear.
        $row = $this->db()->table('kiosk_seller_accounts')->first();
        $this->assertStringNotContainsString('seller-secret', (string) $row->secrets);
        $this->assertStringNotContainsString('seller-secret', (string) $row->details);

        // The token request used the member's keys, and the webhook points at their own URL.
        $token = collect($this->paypal->calls)->firstWhere('path', '/v1/oauth2/token');
        $this->assertSame('Basic '.base64_encode('seller-client-ABCD:seller-secret'), $token['headers']['Authorization'][0]);
        $hook = collect($this->paypal->calls)->first(fn ($c) => $c['method'] === 'POST' && $c['path'] === '/v1/notifications/webhooks');
        $this->assertStringEndsWith('/api/kiosk/webhooks/paypal/'.$row->id, $hook['body']['url']);
    }

    #[Test]
    public function members_list_only_what_they_own_and_never_forum_access(): void
    {
        $base = ['name' => 'X', 'prices' => [['kind' => 'one_off', 'amount' => '5', 'currency' => 'USD']]];

        $this->assertSame(422, $this->api('POST', '/kiosk/selling/listings', self::SELLER, $base + ['unlocks' => [['type' => 'test-item', 'targetId' => 301]]])->getStatusCode());
        $this->assertSame(422, $this->api('POST', '/kiosk/selling/listings', self::SELLER, $base + ['unlocks' => [['type' => 'group', 'targetId' => self::GOLD]]])->getStatusCode());
        $this->assertSame(422, $this->api('POST', '/kiosk/selling/listings', self::SELLER, $base + ['unlocks' => [['type' => 'tag', 'targetId' => self::LOUNGE]]])->getStatusCode());

        // Forum-only fields are ignored.
        $listing = $this->listing(['buyerGroupIds' => [1], 'stock' => 1, 'hidden' => true]);
        $row = $this->db()->table('kiosk_products')->find($listing['id']);
        $this->assertSame([null, null, 0, self::SELLER, 'pending'], [$row->buyer_group_ids, $row->stock, (int) $row->is_hidden, (int) $row->seller_id, $row->review]);
        // Its items are made sellable only once a moderator approves.
        $this->assertSame([], TestItemUnlock::$prepared);
        $this->approve($listing['id']);
        $this->assertSame([201], TestItemUnlock::$prepared);

        // The admin's product list is the forum's own.
        $this->assertSame([], $this->json($this->api('GET', '/kiosk/admin/products', 1))['data']);
    }

    #[Test]
    public function a_listing_sells_only_once_approved_and_pays_the_seller(): void
    {
        $this->connectPayPal();
        $this->paypal->captureValue = '8.00';
        $listing = $this->listing();
        $priceId = $listing['prices'][0]['id'];

        // Waiting for review: not in the store, can't be bought.
        $this->assertSame([], $this->json($this->api('GET', '/kiosk/store', self::BUYER))['data']);
        $this->assertSame(422, $this->api('POST', '/kiosk/checkout', self::BUYER, ['priceId' => $priceId, 'provider' => 'paypal'])->getStatusCode());

        $this->approve($listing['id']);
        $this->assertSame('Refunds within 14 days.', $this->json($this->api('POST', '/kiosk/selling/terms', self::SELLER, ['terms' => 'Refunds within 14 days.']))['data']['terms']);
        $card = $this->json($this->api('GET', '/kiosk/store', self::BUYER))['data'][0];
        $this->assertSame('Refunds within 14 days.', $card['seller']['terms']);
        $this->assertSame(['normal', [['key' => 'paypal', 'mode' => 'sandbox', 'recurring' => true]]], [$card['seller']['username'], $card['providers']]);

        // No forum coupons, and not your own listing.
        $this->assertSame(422, $this->api('POST', '/kiosk/checkout', self::BUYER, ['priceId' => $priceId, 'provider' => 'paypal', 'coupon' => 'X'])->getStatusCode());
        $this->assertSame(422, $this->api('POST', '/kiosk/checkout', self::SELLER, ['priceId' => $priceId, 'provider' => 'paypal'])->getStatusCode());

        $this->paypal->calls = [];
        $start = $this->api('POST', '/kiosk/checkout', self::BUYER, ['priceId' => $priceId, 'provider' => 'paypal']);
        $this->assertSame(200, $start->getStatusCode(), (string) $start->getBody());
        $number = $this->json($start)['data']['number'];

        // 🚨 The order was made with the seller's own keys: the money is theirs.
        $token = collect($this->paypal->calls)->firstWhere('path', '/v1/oauth2/token');
        $this->assertSame('Basic '.base64_encode('seller-client-ABCD:seller-secret'), $token['headers']['Authorization'][0]);

        $purchase = $this->db()->table('kiosk_purchases')->where('number', $number)->first();
        $this->assertSame(self::SELLER, (int) $purchase->seller_id);
        $this->assertNotNull($purchase->seller_account_id);

        $confirmed = $this->json($this->api('POST', '/kiosk/purchases/'.$number.'/confirm', self::BUYER))['data'];
        $this->assertSame('active', $confirmed['status']);
        $this->assertSame(1, $this->db()->table('kiosk_entitlements')->where(['user_id' => self::BUYER, 'type' => 'test-item', 'target_id' => 201])->count());

        // The seller sees the sale and can refund it; nobody else's.
        $sales = $this->json($this->api('GET', '/kiosk/selling', self::SELLER))['data']['sales'];
        $this->assertSame([$number, 'buyer', true], [$sales[0]['number'], $sales[0]['buyer']['username'], $sales[0]['refundable']]);
        $this->assertSame(404, $this->api('POST', '/kiosk/selling/sales/'.$number.'/refund', self::STAFF)->getStatusCode());
        $this->assertSame(200, $this->api('POST', '/kiosk/selling/sales/'.$number.'/refund', self::SELLER)->getStatusCode());
        $this->assertSame('refunded', $this->db()->table('kiosk_purchases')->where('number', $number)->value('status'));
    }

    #[Test]
    public function a_webhook_through_one_account_never_touches_another_payees_purchase(): void
    {
        $this->connectPayPal();
        $this->paypal->captureValue = '8.00';
        $listing = $this->listing();
        $this->approve($listing['id']);
        $number = $this->json($this->api('POST', '/kiosk/checkout', self::BUYER, ['priceId' => $listing['prices'][0]['id'], 'provider' => 'paypal']))['data']['number'];
        $capture = ['id' => 'CAP-X', 'custom_id' => $number, 'status' => 'COMPLETED', 'amount' => ['currency_code' => 'USD', 'value' => '8.00'],
            'links' => [['rel' => 'up', 'href' => 'https://api.sandbox.paypal.com/v2/checkout/orders/'.$this->db()->table('kiosk_purchases')->where('number', $number)->value('provider_ref')]]];

        // The forum's own endpoint: the seller's purchase isn't the forum's to open.
        $this->webhook('PAYMENT.CAPTURE.COMPLETED', $capture);
        $this->assertSame('pending', $this->db()->table('kiosk_purchases')->where('number', $number)->value('status'));

        // The seller's endpoint, checked against the seller's webhook id.
        $account = (int) $this->db()->table('kiosk_seller_accounts')->value('id');
        $event = ['id' => 'WH-S-1', 'event_type' => 'PAYMENT.CAPTURE.COMPLETED', 'resource' => $capture];
        $this->paypal->calls = [];
        $this->boot();
        $this->send($this->request('POST', '/api/kiosk/webhooks/paypal/'.$account, ['json' => $event])
            ->withBody((new StreamFactory())->createStream((string) json_encode($event)))
            ->withHeader('PAYPAL-AUTH-ALGO', 'SHA256withRSA')->withHeader('PAYPAL-CERT-URL', 'https://api.sandbox.paypal.com/v1/notifications/certs/CERT')
            ->withHeader('PAYPAL-TRANSMISSION-ID', 'tid')->withHeader('PAYPAL-TRANSMISSION-SIG', 'sig')->withHeader('PAYPAL-TRANSMISSION-TIME', gmdate('c')));
        $verify = collect($this->paypal->calls)->firstWhere('path', '/v1/notifications/verify-webhook-signature');
        $this->assertSame('WH-1', $verify['body']['webhook_id']);
        $this->assertSame('Basic '.base64_encode('seller-client-ABCD:seller-secret'), collect($this->paypal->calls)->firstWhere('path', '/v1/oauth2/token')['headers']['Authorization'][0]);
        $this->assertSame('active', $this->db()->table('kiosk_purchases')->where('number', $number)->value('status'));
    }

    #[Test]
    public function stripe_sellers_are_paid_on_their_connected_account_with_the_forums_cut(): void
    {
        $this->setting('ernestdefoe-kiosk.stripe_test_connect_webhook_secret', 'whsec_connect');
        $this->setting('ernestdefoe-kiosk.seller_fee_percent', '10');
        $this->stripe->answers['POST /v1/accounts'] = ['id' => 'acct_seller'];
        $this->stripe->answers['POST /v1/account_links'] = ['url' => 'https://connect.stripe.com/setup/s/x'];
        $this->stripe->answers['GET /v1/accounts/acct_seller'] = ['id' => 'acct_seller', 'charges_enabled' => true];
        $this->stripe->amountTotal = 800;

        $go = $this->json($this->api('POST', '/kiosk/selling/accounts/stripe', self::SELLER))['meta']['redirect'];
        $this->assertSame('https://connect.stripe.com/setup/s/x', $go);
        $account = (int) $this->db()->table('kiosk_seller_accounts')->value('id');
        $this->assertSame(302, $this->api('GET', '/kiosk/selling/stripe/'.$account, self::SELLER)->getStatusCode());
        $this->assertSame('connected', $this->db()->table('kiosk_seller_accounts')->value('status'));

        $listing = $this->listing();
        $this->approve($listing['id']);
        $this->stripe->calls = [];
        $start = $this->api('POST', '/kiosk/checkout', self::BUYER, ['priceId' => $listing['prices'][0]['id'], 'provider' => 'stripe']);
        $this->assertSame(200, $start->getStatusCode(), (string) $start->getBody());

        $session = collect($this->stripe->calls)->firstWhere('path', '/v1/checkout/sessions');
        $this->assertSame('acct_seller', $session['headers']['Stripe-Account'][0]);
        $this->assertSame('80', $session['params']['payment_intent_data']['application_fee_amount']);

        // The forum's own endpoint secret can't speak for the member's account…
        $number = $this->json($start)['data']['number'];
        $object = ['id' => 'cs_test_1', 'status' => 'complete', 'client_reference_id' => $number, 'payment_status' => 'paid', 'amount_total' => 800, 'currency' => 'usd', 'payment_intent' => 'pi_1', 'mode' => 'payment'];
        $this->signedStripe('checkout.session.completed', $object, 'whsec_test', null);
        $this->assertSame('pending', $this->db()->table('kiosk_purchases')->where('number', $number)->value('status'));

        // …the Connect endpoint's event for their account opens it, with the fee recorded.
        $this->signedStripe('checkout.session.completed', $object, 'whsec_connect', 'acct_seller');
        $this->assertSame('active', $this->db()->table('kiosk_purchases')->where('number', $number)->value('status'));
        $this->assertSame(80, (int) $this->db()->table('kiosk_payments')->value('fee_cents'));
    }

    private function signedStripe(string $type, array $object, string $secret, ?string $account): void
    {
        $this->boot();
        $raw = (string) json_encode(array_filter(['id' => 'evt_'.uniqid(), 'type' => $type, 'account' => $account, 'data' => ['object' => $object]]));
        $t = time();
        $this->send($this->request('POST', '/api/kiosk/webhooks/stripe', ['json' => json_decode($raw, true)])
            ->withBody((new StreamFactory())->createStream($raw))
            ->withHeader('Stripe-Signature', 't='.$t.',v1='.hash_hmac('sha256', $t.'.'.$raw, $secret)));
    }

    /** A seller with a connected PayPal account and one approved listing, made directly. */
    private function seller(int $id): void
    {
        $now = \Carbon\Carbon::now();
        $this->db()->table('users')->insertOrIgnore(['id' => $id, 'username' => 'seller'.$id, 'email' => "s$id@machine.local", 'password' => 'x', 'is_email_confirmed' => 1, 'joined_at' => $now]);
        $account = new \Ernestdefoe\Kiosk\Seller\Account(['user_id' => $id, 'provider' => 'paypal', 'mode' => 'sandbox', 'status' => 'connected']);
        $account->setCredentials(['client_id' => 'c'.$id, 'secret' => 's'.$id, 'webhook_id' => 'WH-'.$id]);
        $account->save();
        $product = $this->db()->table('kiosk_products')->insertGetId(['name' => "Listing $id", 'slug' => "listing-$id", 'seller_id' => $id, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        $this->db()->table('kiosk_prices')->insert(['product_id' => $product, 'amount_cents' => 300, 'currency' => 'USD', 'kind' => 'one_off', 'created_at' => $now]);
        $this->db()->table('kiosk_product_unlocks')->insert(['product_id' => $product, 'type' => 'test-item', 'target_id' => $id * 100 + 1]);
    }

    private function queries(?int $user, string $path, array $query = []): int
    {
        $this->api('GET', $path, $user, [], $query);
        $this->db()->flushQueryLog();
        $this->db()->enableQueryLog();
        $this->assertSame(200, $this->api('GET', $path, $user, [], $query)->getStatusCode());
        $n = count($this->db()->getQueryLog());
        $this->db()->disableQueryLog();

        return $n;
    }

    #[Test]
    public function the_store_costs_the_same_for_one_seller_or_ten(): void
    {
        $this->api('GET', '/kiosk/store'); // boot
        $this->seller(10);
        $one = [$this->queries(null, '/kiosk/store'), $this->queries(self::BUYER, '/kiosk/store'), $this->queries(self::BUYER, '/discussions')];
        $this->assertCount(1, $this->json($this->api('GET', '/kiosk/store', self::BUYER))['data']);

        foreach (range(11, 19) as $id) {
            $this->seller($id);
        }
        $this->assertCount(10, $this->json($this->api('GET', '/kiosk/store', self::BUYER))['data']);
        $this->assertSame($one, [$this->queries(null, '/kiosk/store'), $this->queries(self::BUYER, '/kiosk/store'), $this->queries(self::BUYER, '/discussions')]);
    }

    #[Test]
    public function a_checkout_link_that_isnt_https_is_never_handed_to_the_buyer(): void
    {
        // Ship-check: a member's own server picks some checkout links; javascript: would run on the forum.
        $this->connectPayPal();
        $listing = $this->listing();
        $this->approve($listing['id']);
        $this->paypal->answers['POST /v2/checkout/orders'] = [201, ['id' => 'ORDER-X', 'status' => 'PAYER_ACTION_REQUIRED', 'links' => [['rel' => 'payer-action', 'href' => 'javascript:alert(document.domain)']]]];

        $response = $this->api('POST', '/kiosk/checkout', self::BUYER, ['priceId' => $listing['prices'][0]['id'], 'provider' => 'paypal']);
        $this->assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        $this->assertStringNotContainsString('javascript', (string) $response->getBody());
        $this->assertSame(0, $this->db()->table('kiosk_purchases')->count());
    }

    #[Test]
    public function a_sellers_event_ids_are_theirs_alone_and_accounts_with_live_subscriptions_stay(): void
    {
        $this->connectPayPal();
        $account = (int) $this->db()->table('kiosk_seller_accounts')->value('id');
        $event = ['id' => 'WH-SHARED', 'event_type' => 'PAYMENT.CAPTURE.COMPLETED', 'resource' => ['id' => 'CAP-1', 'custom_id' => 'K-NOPE']];
        $this->boot();
        $this->send($this->request('POST', '/api/kiosk/webhooks/paypal/'.$account, ['json' => $event])
            ->withBody((new StreamFactory())->createStream((string) json_encode($event)))
            ->withHeader('PAYPAL-AUTH-ALGO', 'SHA256withRSA')->withHeader('PAYPAL-CERT-URL', 'https://api.sandbox.paypal.com/v1/notifications/certs/CERT')
            ->withHeader('PAYPAL-TRANSMISSION-ID', 'tid')->withHeader('PAYPAL-TRANSMISSION-SIG', 'sig')->withHeader('PAYPAL-TRANSMISSION-TIME', gmdate('c')));
        $this->assertSame(['paypal', 'a'.$account.':WH-SHARED'], [(string) $this->db()->table('kiosk_webhook_events')->value('provider'), $this->db()->table('kiosk_webhook_events')->value('event_id')]);

        // The forum's own event with the same id is still the forum's to act on.
        $this->webhook('PAYMENT.CAPTURE.COMPLETED', ['id' => 'CAP-2', 'custom_id' => 'K-NOPE'], 'WH-SHARED');
        $this->assertSame(2, $this->db()->table('kiosk_webhook_events')->count());

        $now = \Carbon\Carbon::now();
        $this->db()->table('kiosk_purchases')->insert(['number' => 'K-SUBSCRIBED', 'user_id' => self::BUYER, 'product_name' => 'X', 'amount_cents' => 300, 'currency' => 'USD',
            'kind' => 'recurring', 'interval' => 'month', 'provider' => 'paypal', 'status' => 'active', 'seller_id' => self::SELLER, 'seller_account_id' => $account, 'created_at' => $now, 'updated_at' => $now]);
        $this->assertSame(422, $this->api('DELETE', '/kiosk/selling/accounts/paypal', self::SELLER)->getStatusCode());
        $this->assertSame(1, $this->db()->table('kiosk_seller_accounts')->count());
    }

    #[Test]
    public function a_new_listing_must_sell_something(): void
    {
        $response = $this->api('POST', '/kiosk/selling/listings', self::SELLER, ['name' => 'Nothing', 'prices' => [['kind' => 'one_off', 'amount' => '5', 'currency' => 'USD']]]);
        $this->assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function members_servers_must_be_public_https(): void
    {
        foreach (['http://1.1.1.1', 'https://127.0.0.1', 'https://10.0.0.5', 'https://169.254.169.254', 'https://100.64.0.1', 'https://[::1]', 'https://[::ffff:127.0.0.1]', 'https://0177.0.0.1', 'https://user:pw@1.1.1.1', 'https://localhost'] as $url) {
            $this->assertNull(\Ernestdefoe\Kiosk\Seller\SafeHost::resolve($url), $url);
        }
        $this->assertSame('1.1.1.1', \Ernestdefoe\Kiosk\Seller\SafeHost::resolve('https://1.1.1.1/btcpay')['ip']);
    }

    #[Test]
    public function a_seller_can_be_paid_at_just_their_paypal_email_for_one_time_prices(): void
    {
        $data = $this->json($this->api('POST', '/kiosk/selling/accounts/paypal', self::SELLER, ['email' => 'Seller@Example.com']))['data'];
        $this->assertSame(['connected', 'seller@example.com', true], [$data['providers'][0]['status'], $data['providers'][0]['hint'], $data['providers'][0]['byEmail']]);
        $this->assertNull($this->db()->table('kiosk_seller_accounts')->value('secrets'));

        $listing = $this->listing(['prices' => [['kind' => 'one_off', 'amount' => '8.00', 'currency' => 'USD'], ['kind' => 'recurring', 'interval' => 'month', 'amount' => '2.00', 'currency' => 'USD']]]);
        $this->approve($listing['id']);
        $card = $this->json($this->api('GET', '/kiosk/store', self::BUYER))['data'][0];
        $this->assertSame([['key' => 'paypal', 'mode' => 'sandbox', 'recurring' => false]], $card['providers']);

        // A subscription can't go to an email payee.
        $this->assertSame(422, $this->api('POST', '/kiosk/checkout', self::BUYER, ['priceId' => $listing['prices'][1]['id'], 'provider' => 'paypal'])->getStatusCode());

        $this->paypal->calls = [];
        $this->paypal->captureValue = '8.00';
        $start = $this->api('POST', '/kiosk/checkout', self::BUYER, ['priceId' => $listing['prices'][0]['id'], 'provider' => 'paypal']);
        $this->assertSame(200, $start->getStatusCode(), (string) $start->getBody());
        $number = $this->json($start)['data']['number'];

        // The forum's own app makes the order; the seller is the payee.
        $order = collect($this->paypal->calls)->firstWhere('path', '/v2/checkout/orders');
        $this->assertSame(['email_address' => 'seller@example.com'], $order['body']['purchase_units'][0]['payee']);
        $this->assertSame('Basic '.base64_encode('client:secret'), collect($this->paypal->calls)->firstWhere('path', '/v1/oauth2/token')['headers']['Authorization'][0]);

        // Its events come to the forum's endpoint, which may act on it.
        $ref = $this->db()->table('kiosk_purchases')->where('number', $number)->value('provider_ref');
        $this->webhook('PAYMENT.CAPTURE.COMPLETED', ['id' => 'CAP-E', 'custom_id' => $number, 'status' => 'COMPLETED', 'amount' => ['currency_code' => 'USD', 'value' => '8.00'],
            'links' => [['rel' => 'up', 'href' => 'https://api.sandbox.paypal.com/v2/checkout/orders/'.$ref]]]);
        $this->assertSame('active', $this->db()->table('kiosk_purchases')->where('number', $number)->value('status'));

        // The seller refunds in their PayPal; Refund here records it without asking PayPal.
        $this->paypal->calls = [];
        $this->assertSame(200, $this->api('POST', '/kiosk/selling/sales/'.$number.'/refund', self::SELLER)->getStatusCode());
        $this->assertSame('refunded', $this->db()->table('kiosk_purchases')->where('number', $number)->value('status'));
        $this->assertNull(collect($this->paypal->calls)->first(fn ($c) => str_ends_with($c['path'], '/refund')));

        // Switching to their own app's keys stops the email payee.
        $this->connectPayPal();
        $this->assertNull($this->db()->table('kiosk_seller_accounts')->value('external_id'));
    }
}
