<?php

namespace Ernestdefoe\Kiosk\Provider;

use Flarum\Settings\SettingsRepositoryInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

/**
 * Stripe's REST API, and nothing about Kiosk's own rules.
 *
 * Plain HTTP rather than stripe-php: a handful of calls do not need a 4 MB
 * SDK on every forum, and it keeps both providers built the same way.
 *
 * The admin pastes ONE key. Test or live is read from its prefix, and
 * Connect creates the webhook endpoint and keeps its signing secret, so
 * nobody visits Stripe's dashboard to set up products, prices or webhooks.
 */
class StripeClient
{
    private const BASE = 'https://api.stripe.com';

    /**
     * 🚨 Pinned. Stripe changes payload shapes between versions (2025-03-31
     * moved current_period_end off the subscription), and the account's
     * default version is whatever the owner's account was created with.
     * Every request and the webhook endpoint use this one.
     */
    public const API_VERSION = '2024-06-20';

    private const PREFIX = 'ernestdefoe-kiosk.stripe_';

    public bool $rejected = false;

    /** A member's connected account id (acct_…), when acting for one. */
    private ?string $connected = null;

    public function __construct(
        private Client $http,
        private SettingsRepositoryInterface $settings,
        private LoggerInterface $log,
    ) {
    }

    /** A copy that acts on a member's connected account (direct charges). */
    public function connected(string $accountId): static
    {
        $copy = clone $this;
        $copy->connected = $accountId;

        return $copy;
    }

    public function mode(): string
    {
        return $this->settings->get(self::PREFIX.'mode') === 'live' ? 'live' : 'test';
    }

    public function setting(string $key, ?string $mode = null): string
    {
        return trim((string) $this->settings->get(self::PREFIX.($mode ?? $this->mode()).'_'.$key));
    }

    /** "test" or "live" from a key's prefix, or null for something that is not a secret key. */
    public static function modeOfKey(string $key): ?string
    {
        return match (true) {
            str_starts_with($key, 'sk_test_'), str_starts_with($key, 'rk_test_') => 'test',
            str_starts_with($key, 'sk_live_'), str_starts_with($key, 'rk_live_') => 'live',
            default => null,
        };
    }

    public function isConnected(?string $mode = null): bool
    {
        return $this->setting('secret', $mode) !== '' && $this->setting('webhook_secret', $mode) !== '';
    }

    public function wasRejected(): bool
    {
        return $this->rejected;
    }

    /**
     * One API call, form-encoded as Stripe expects. Returns the decoded body
     * with `_status` added, or null when Stripe could not be reached.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    public function call(string $method, string $path, array $params = [], ?string $idempotencyKey = null, ?string $mode = null): ?array
    {
        $key = $this->setting('secret', $mode);

        if ($key === '') {
            return null;
        }

        $headers = ['Authorization' => 'Bearer '.$key, 'Stripe-Version' => self::API_VERSION];

        // A member's connected account: the forum's key, acting on their account.
        if ($this->connected !== null) {
            $headers['Stripe-Account'] = $this->connected;
        }

        // 🚨 A dropped reply must not let a retry make a second session or refund.
        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        $query = self::encode($params);

        try {
            $res = $this->http->request($method, self::BASE.$path.($method === 'GET' && $query !== '' ? '?'.$query : ''), [
                'headers' => $headers + ($method === 'GET' ? [] : ['Content-Type' => 'application/x-www-form-urlencoded']),
                'body' => $method === 'GET' ? null : $query,
                'timeout' => 20,
                'http_errors' => false,
            ]);
        } catch (GuzzleException $e) {
            $this->log->error("[kiosk] Stripe $method $path failed: ".$e->getMessage());

            return null;
        }

        $body = json_decode((string) $res->getBody(), true);
        $body = is_array($body) ? $body : [];
        $body['_status'] = $res->getStatusCode();

        if ($res->getStatusCode() === 401) {
            $this->rejected = true;
        }

        if ($res->getStatusCode() >= 400) {
            $this->log->warning("[kiosk] Stripe $method $path answered ".$res->getStatusCode().': '.json_encode(array_intersect_key((array) ($body['error'] ?? []), array_flip(['type', 'code', 'message', 'param']))));
        }

        return $body;
    }

    /**
     * Stripe's form encoding: nested arrays as a[b][0]=…, booleans as words.
     *
     * @param array<string, mixed> $params
     */
    public static function encode(array $params): string
    {
        array_walk_recursive($params, function (&$v) {
            if (is_bool($v)) {
                $v = $v ? 'true' : 'false';
            }
        });

        return http_build_query($params, '', '&', PHP_QUERY_RFC1738);
    }

    /**
     * Whether a delivery really came from Stripe, and for which mode.
     *
     * Stripe signs `{timestamp}.{body}` with the endpoint's secret (HMAC
     * SHA-256). Checked locally with hash_equals, and refused if the timestamp
     * is more than five minutes off, so a captured delivery cannot be replayed.
     *
     * @param array<string, array<int, string>|string> $headers
     */
    public function verifyWebhook(array $headers, string $rawBody, ?int $now = null): ?string
    {
        $header = '';
        foreach ($headers as $name => $value) {
            if (strcasecmp((string) $name, 'Stripe-Signature') === 0) {
                $header = is_array($value) ? (string) reset($value) : (string) $value;
            }
        }

        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            [$k, $v] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($k === 't' && ctype_digit($v)) {
                $timestamp = (int) $v;
            } elseif ($k === 'v1') {
                $signatures[] = $v;
            }
        }

        if ($timestamp === null || ! $signatures || abs(($now ?? time()) - $timestamp) > 300) {
            return null;
        }

        // The forum's own endpoint, then the one for members' connected
        // accounts (Stripe signs each endpoint with its own secret).
        foreach (['live', 'test'] as $mode) {
            foreach (['webhook_secret', 'connect_webhook_secret'] as $which) {
                $secret = $this->setting($which, $mode);
                if ($secret === '') {
                    continue;
                }

                $expected = hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);
                foreach ($signatures as $signature) {
                    if (hash_equals($expected, $signature)) {
                        return $mode;
                    }
                }
            }
        }

        return null;
    }

    /** What Kiosk acts on. */
    public const WEBHOOK_EVENTS = [
        'checkout.session.completed', 'checkout.session.async_payment_succeeded',
        'invoice.paid', 'invoice.payment_failed',
        'customer.subscription.updated', 'customer.subscription.deleted',
        'charge.refunded', 'charge.dispute.created',
    ];

    /**
     * Check the key, then (re)create this forum's webhook endpoint and keep
     * its signing secret.
     *
     * 🚨 Stripe returns an endpoint's secret only when it is created, so an
     * existing endpoint for this URL is replaced rather than reused: there is
     * no way to read its secret back.
     *
     * @return array{ok: bool, mode: string, step?: string, error?: string, created?: bool}
     */
    public function connect(string $webhookUrl, string $mode): array
    {
        $this->rejected = false;
        $account = $this->call('GET', '/v1/account', [], null, $mode);

        if ($account === null) {
            return ['ok' => false, 'mode' => $mode, 'step' => 'unreachable'];
        }
        if (($account['_status'] ?? 500) >= 400) {
            return ['ok' => false, 'mode' => $mode, 'step' => 'credentials'];
        }

        $list = $this->call('GET', '/v1/webhook_endpoints', ['limit' => 100], null, $mode);
        foreach ($list['data'] ?? [] as $endpoint) {
            if (rtrim((string) ($endpoint['url'] ?? ''), '/') === rtrim($webhookUrl, '/')) {
                $this->call('DELETE', '/v1/webhook_endpoints/'.rawurlencode((string) $endpoint['id']), [], null, $mode);
            }
        }

        $made = $this->call('POST', '/v1/webhook_endpoints', [
            'url' => $webhookUrl,
            'enabled_events' => self::WEBHOOK_EVENTS,
            'api_version' => self::API_VERSION,
            'description' => 'Kiosk (Flarum)',
        ], null, $mode);

        if (! isset($made['id'], $made['secret'])) {
            return ['ok' => false, 'mode' => $mode, 'step' => 'webhook', 'error' => (string) ($made['error']['message'] ?? '')];
        }

        $this->settings->set(self::PREFIX.$mode.'_webhook_id', (string) $made['id']);
        $this->settings->set(self::PREFIX.$mode.'_webhook_secret', (string) $made['secret']);

        return ['ok' => true, 'mode' => $mode, 'created' => true];
    }

    /**
     * Members' sales: a second webhook endpoint, for events on connected
     * accounts (Stripe sends those only to an endpoint made with connect=true).
     * It lives at its own URL so connect() above, which replaces the forum's
     * endpoint by URL, never deletes it. Fails when Connect isn't switched on
     * in the forum's Stripe dashboard yet.
     *
     * @return array{ok: bool, mode: string, step?: string, error?: string}
     */
    public function connectSellers(string $webhookUrl, string $mode): array
    {
        $url = $webhookUrl.'?connect=1';
        $list = $this->call('GET', '/v1/webhook_endpoints', ['limit' => 100], null, $mode);

        if ($list === null) {
            return ['ok' => false, 'mode' => $mode, 'step' => 'unreachable'];
        }

        foreach ($list['data'] ?? [] as $endpoint) {
            if (($endpoint['url'] ?? '') === $url) {
                $this->call('DELETE', '/v1/webhook_endpoints/'.rawurlencode((string) $endpoint['id']), [], null, $mode);
            }
        }

        $made = $this->call('POST', '/v1/webhook_endpoints', [
            'url' => $url,
            'connect' => true,
            'enabled_events' => [...self::WEBHOOK_EVENTS, 'account.updated'],
            'api_version' => self::API_VERSION,
            'description' => 'Kiosk (Flarum): members\' sales',
        ], null, $mode);

        if (! isset($made['id'], $made['secret'])) {
            return ['ok' => false, 'mode' => $mode, 'step' => 'connect', 'error' => (string) ($made['error']['message'] ?? '')];
        }

        $this->settings->set(self::PREFIX.$mode.'_connect_webhook_secret', (string) $made['secret']);

        return ['ok' => true, 'mode' => $mode];
    }

    /**
     * A member's Stripe account, made by the forum's platform account, and
     * the link to Stripe's own sign-up for it. Stripe collects their identity
     * and bank details; Kiosk keeps only the acct_… id.
     *
     * @return array{account?: string, url?: string, error?: string}
     */
    public function onboard(?string $accountId, string $email, string $returnUrl, string $refreshUrl): array
    {
        if ($accountId === null || $accountId === '') {
            $made = $this->call('POST', '/v1/accounts', ['type' => 'standard', 'email' => $email]);
            if (! isset($made['id'])) {
                return ['error' => (string) ($made['error']['message'] ?? 'unreachable')];
            }
            $accountId = (string) $made['id'];
        }

        $link = $this->call('POST', '/v1/account_links', [
            'account' => $accountId,
            'type' => 'account_onboarding',
            'return_url' => $returnUrl,
            'refresh_url' => $refreshUrl,
        ]);

        return isset($link['url']) ? ['account' => $accountId, 'url' => (string) $link['url']] : ['account' => $accountId, 'error' => (string) ($link['error']['message'] ?? 'unreachable')];
    }

    /** Whether a member's connected account can take payments yet. */
    public function canCharge(string $accountId): ?bool
    {
        $account = $this->call('GET', '/v1/accounts/'.rawurlencode($accountId));

        return $account === null || ($account['_status'] ?? 500) >= 300 ? null : (bool) ($account['charges_enabled'] ?? false);
    }
}
