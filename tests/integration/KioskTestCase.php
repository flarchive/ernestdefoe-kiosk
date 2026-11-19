<?php

namespace Ernestdefoe\Kiosk\Tests\integration;

use Carbon\Carbon;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use Laminas\Diactoros\StreamFactory;
use Psr\Http\Message\ResponseInterface;

/**
 * Members: NORMAL (2, from flarum/testing), BUYER (3), STAFF (4, in the
 * Mods group 4), UNCONFIRMED (5). Group 5 "Gold" is for sale; tag 1 is
 * "General", tag 2 "Lounge"; discussion 1 is in the Lounge.
 * PayPal is FakePayPal, connected in sandbox mode.
 */
abstract class KioskTestCase extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected const BUYER = 3;
    protected const STAFF = 4;
    protected const UNCONFIRMED = 5;
    protected const GOLD = 5;
    protected const LOUNGE = 2;

    protected FakePayPal $paypal;
    protected FakeStripe $stripe;
    protected FakeCrypto $crypto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-tags', 'ernestdefoe-kiosk');

        $hash = $this->normalUser()['password'];
        $now = Carbon::now();

        $this->prepareDatabase([
            'users' => [
                $this->normalUser(),
                ['id' => self::BUYER, 'username' => 'buyer', 'email' => 'buyer@machine.local', 'password' => $hash, 'is_email_confirmed' => 1],
                ['id' => self::STAFF, 'username' => 'staff', 'email' => 'staff@machine.local', 'password' => $hash, 'is_email_confirmed' => 1],
                ['id' => self::UNCONFIRMED, 'username' => 'unconfirmed', 'email' => 'u@machine.local', 'password' => $hash, 'is_email_confirmed' => 0],
            ],
            'groups' => [['id' => self::GOLD, 'name_singular' => 'Gold', 'name_plural' => 'Gold members', 'is_hidden' => 0]],
            'group_user' => [['user_id' => self::STAFF, 'group_id' => 4]],
            'group_permission' => [['group_id' => 4, 'permission' => 'discussion.hide'], ['group_id' => 4, 'permission' => 'kiosk.readPaid'], ['group_id' => 3, 'permission' => 'discussion.reply']],
            'tags' => [
                ['id' => 1, 'name' => 'General', 'slug' => 'general', 'position' => 0],
                ['id' => self::LOUNGE, 'name' => 'Lounge', 'slug' => 'lounge', 'position' => 1],
            ],
            'discussions' => [['id' => 1, 'title' => 'Members only', 'slug' => 'members-only', 'user_id' => 1, 'created_at' => $now, 'last_posted_at' => $now, 'comment_count' => 2, 'first_post_id' => 1, 'last_post_id' => 2, 'last_post_number' => 2]],
            'posts' => [
                ['id' => 1, 'discussion_id' => 1, 'user_id' => 1, 'type' => 'comment', 'content' => '<t>Behind the rope '.str_repeat('words ', 80).'FINALE-SECRET</t>', 'number' => 1, 'created_at' => $now],
                ['id' => 2, 'discussion_id' => 1, 'user_id' => 1, 'type' => 'comment', 'content' => '<t>REPLY-SECRET</t>', 'number' => 2, 'created_at' => $now],
            ],
            'discussion_tag' => [['discussion_id' => 1, 'tag_id' => self::LOUNGE]],
        ]);

        $this->setting('ernestdefoe-kiosk.paypal_mode', 'sandbox');
        $this->setting('ernestdefoe-kiosk.paypal_sandbox_client_id', 'client');
        $this->setting('ernestdefoe-kiosk.paypal_sandbox_secret', 'secret');
        $this->setting('ernestdefoe-kiosk.paypal_sandbox_webhook_id', 'WH-1');

        $this->setting('ernestdefoe-kiosk.stripe_mode', 'test');
        $this->setting('ernestdefoe-kiosk.stripe_test_secret', 'sk_test_123');
        $this->setting('ernestdefoe-kiosk.stripe_test_webhook_secret', 'whsec_test');

        $this->paypal = new FakePayPal();
        $this->stripe = new FakeStripe();
        $this->crypto = new FakeCrypto();
    }

    /** Boot the app with PayPal faked. */
    protected function boot(): void
    {
        $this->app()->getContainer()->instance(Client::class, new Client(['handler' => HandlerStack::create(fn ($request) => Create::promiseFor(
            match (true) {
                $request->getUri()->getHost() === 'api.stripe.com' => ($this->stripe)($request),
                str_contains($request->getUri()->getHost(), 'paypal.com') => ($this->paypal)($request),
                default => ($this->crypto)($request),
            }
        ))]));
    }

    /** @param array<string, mixed> $data */
    protected function api(string $method, string $path, ?int $as = null, array $data = [], array $query = []): ResponseInterface
    {
        $this->boot();
        $options = array_filter(['authenticatedAs' => $as, 'json' => $method === 'GET' ? null : ['data' => $data]]);
        $request = $this->request($method, '/api'.$path, $options);
        if ($query) {
            $request = $request->withQueryParams($query);
        }

        return $this->send($request);
    }

    /** @return array<string, mixed> */
    protected function json(ResponseInterface $response): array
    {
        return (array) json_decode((string) $response->getBody(), true);
    }

    /**
     * A product made through the admin API.
     *
     * @param list<array{type: string, targetId: int}> $unlocks
     * @param list<array<string, mixed>> $prices
     * @return array<string, mixed>
     */
    protected function product(array $unlocks, array $prices, array $extra = []): array
    {
        $response = $this->api('POST', '/kiosk/admin/products', 1, ['name' => 'Gold', 'unlocks' => $unlocks, 'prices' => $prices] + $extra);
        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return $this->json($response)['data'];
    }

    /** A webhook as PayPal would send it. @param array<string, mixed> $resource */
    protected function webhook(string $type, array $resource, ?string $id = null): ResponseInterface
    {
        $this->boot();
        $event = ['id' => $id ?? 'WH-EVT-'.uniqid(), 'event_type' => $type, 'resource' => $resource];

        // The raw bytes, as PayPal sends them: the signature check reads the
        // body itself, not Flarum's parsed copy.
        return $this->send($this->request('POST', '/api/kiosk/webhooks/paypal', ['json' => $event])
            ->withBody((new StreamFactory())->createStream((string) json_encode($event)))
            ->withHeader('PAYPAL-AUTH-ALGO', 'SHA256withRSA')
            ->withHeader('PAYPAL-CERT-URL', 'https://api.sandbox.paypal.com/v1/notifications/certs/CERT')
            ->withHeader('PAYPAL-TRANSMISSION-ID', 'tid')
            ->withHeader('PAYPAL-TRANSMISSION-SIG', 'sig')
            ->withHeader('PAYPAL-TRANSMISSION-TIME', gmdate('c')));
    }

    protected function db(): \Illuminate\Database\ConnectionInterface
    {
        return $this->database();
    }

    /** Whether this member can see discussion 1, in the Lounge. */
    protected function seesLounge(int $userId): bool
    {
        return $this->api('GET', '/discussions/1', $userId)->getStatusCode() === 200;
    }

    /**
     * A Stripe webhook, signed the way Stripe signs it.
     *
     * @param array<string, mixed> $object
     */
    protected function stripeWebhook(string $type, array $object, ?string $id = null, string $secret = 'whsec_test', ?int $at = null): ResponseInterface
    {
        $this->boot();
        $raw = (string) json_encode(['id' => $id ?? 'evt_'.uniqid(), 'type' => $type, 'data' => ['object' => $object]]);
        $t = $at ?? time();

        return $this->send($this->request('POST', '/api/kiosk/webhooks/stripe', ['json' => json_decode($raw, true)])
            ->withBody((new StreamFactory())->createStream($raw))
            ->withHeader('Stripe-Signature', 't='.$t.',v1='.hash_hmac('sha256', $t.'.'.$raw, $secret)));
    }
}
