<?php

namespace Ernestdefoe\Kiosk\Provider;

use Flarum\Settings\SettingsRepositoryInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

/**
 * PayPal's REST API, and nothing about Kiosk's own rules.
 *
 * Sandbox and live each keep their own Client ID, secret and webhook id, so
 * switching Test mode off asks only for the live keys and switching it back
 * does not lose the sandbox ones. Adapted from Client Area's PayPal service,
 * which has taken real money since 2026-09; its 🚨 notes are kept.
 */
class PayPalClient
{
    private const LIVE = 'https://api-m.paypal.com';
    private const SANDBOX = 'https://api-m.sandbox.paypal.com';

    private const PREFIX = 'ernestdefoe-kiosk.paypal_';

    /** @var array<string, string> mode => token */
    private array $tokens = [];

    /**
     * True when PayPal answered and refused us, as opposed to not answering.
     * An outage is worth retrying and a wrong key never is.
     */
    public bool $rejected = false;

    /** @var array<string, string>|null a member's own app (client_id, secret, webhook_id) instead of the forum's */
    private ?array $own = null;

    private ?string $ownMode = null;

    public function __construct(
        private Client $http,
        private SettingsRepositoryInterface $settings,
        private LoggerInterface $log,
    ) {
    }

    /** A copy that talks to a member's own PayPal app. @param array<string, string> $credentials */
    public function forCredentials(array $credentials, string $mode): static
    {
        $copy = clone $this;
        $copy->own = $credentials;
        $copy->ownMode = $mode === 'live' ? 'live' : 'sandbox';
        $copy->tokens = [];
        $copy->rejected = false;

        return $copy;
    }

    /** Whether PayPal answered our last token request with a refusal. */
    public function wasRejected(): bool
    {
        return $this->rejected;
    }

    public function mode(): string
    {
        return $this->ownMode ?? ($this->settings->get(self::PREFIX.'mode') === 'live' ? 'live' : 'sandbox');
    }

    public function setting(string $key, ?string $mode = null): string
    {
        if ($this->own !== null) {
            return trim($this->own[$key] ?? '');
        }

        return trim((string) $this->settings->get(self::PREFIX.($mode ?? $this->mode()).'_'.$key));
    }

    public function hasCredentials(?string $mode = null): bool
    {
        return $this->setting('client_id', $mode) !== '' && $this->setting('secret', $mode) !== '';
    }

    public function isConnected(?string $mode = null): bool
    {
        return $this->hasCredentials($mode) && $this->setting('webhook_id', $mode) !== '';
    }

    private function base(?string $mode = null): string
    {
        return ($mode ?? $this->mode()) === 'live' ? self::LIVE : self::SANDBOX;
    }

    public function token(?string $mode = null): ?string
    {
        $mode ??= $this->mode();

        if (isset($this->tokens[$mode])) {
            return $this->tokens[$mode];
        }

        try {
            $res = $this->http->post($this->base($mode).'/v1/oauth2/token', [
                'auth' => [$this->setting('client_id', $mode), $this->setting('secret', $mode)],
                'form_params' => ['grant_type' => 'client_credentials'],
                'timeout' => 15,
                'http_errors' => false,
            ]);

            $body = json_decode((string) $res->getBody(), true);

            if (isset($body['access_token'])) {
                return $this->tokens[$mode] = (string) $body['access_token'];
            }

            /*
             * 🚨 The commonest cause is live keys pasted into the sandbox
             * fields or the reverse. The keys are not wrong on their own,
             * they are wrong for where they were sent, so log the environment
             * with the failure.
             */
            $this->rejected = true;
            $this->log->error('[kiosk] PayPal rejected the credentials', [
                'status' => $res->getStatusCode(),
                'environment' => $mode,
                'error' => $body['error'] ?? null,
                'description' => $body['error_description'] ?? null,
            ]);

            return null;
        } catch (GuzzleException $e) {
            $this->log->error('[kiosk] PayPal token request failed: '.$e->getMessage());

            return null;
        }
    }

    /**
     * One API call. Returns the decoded body with `_status` added, or null
     * when PayPal could not be reached or our credentials were refused.
     *
     * @param array<mixed>|object|null $json
     * @return array<string, mixed>|null
     */
    public function call(string $method, string $path, array|object|null $json = null, ?string $requestId = null, ?string $mode = null): ?array
    {
        $token = $this->token($mode);

        if ($token === null) {
            return null;
        }

        $headers = ['Authorization' => 'Bearer '.$token, 'Content-Type' => 'application/json', 'Prefer' => 'return=representation'];

        // 🚨 A dropped reply must not let a retry open a second order or
        // subscription: PayPal returns the original for a repeated request id.
        if ($requestId !== null) {
            $headers['PayPal-Request-Id'] = $requestId;
        }

        try {
            $res = $this->http->request($method, $this->base($mode).$path, array_filter([
                'headers' => $headers,
                'json' => $json,
                'timeout' => 20,
                'http_errors' => false,
            ], fn ($v) => $v !== null));
        } catch (GuzzleException $e) {
            $this->log->error("[kiosk] PayPal $method $path failed: ".$e->getMessage());

            return null;
        }

        $body = json_decode((string) $res->getBody(), true);
        $body = is_array($body) ? $body : [];
        $body['_status'] = $res->getStatusCode();

        if ($res->getStatusCode() >= 400) {
            $this->log->warning("[kiosk] PayPal $method $path answered ".$res->getStatusCode().': '.json_encode(array_intersect_key($body, array_flip(['name', 'message', 'details', 'debug_id']))));
        }

        return $body;
    }

    /** The link PayPal sends the buyer to. 🚨 "approve" or "payer-action", depending on the request. */
    public static function approveLink(array $body): ?string
    {
        foreach ($body['links'] ?? [] as $link) {
            if (in_array($link['rel'] ?? '', ['approve', 'payer-action'], true)) {
                return (string) ($link['href'] ?? '') ?: null;
            }
        }

        return null;
    }

    /**
     * The verification request, with the event exactly as PayPal sent it.
     *
     * 🚨 Never decoded and re-encoded: PayPal checks the signature against the
     * event it gets back, and PHP turns an empty object `{}` into `[]` on the
     * way through, which failed every CHECKOUT.ORDER.APPROVED in Client Area.
     *
     * @param array<string, string> $fields
     */
    public static function verificationBody(array $fields, string $rawEvent): string
    {
        return substr((string) json_encode($fields, JSON_UNESCAPED_SLASHES), 0, -1).',"webhook_event":'.trim($rawEvent).'}';
    }

    /**
     * Whether this delivery really came from PayPal, and for which mode.
     *
     * 🚨 Delegated to PayPal's own verify endpoint rather than checked locally
     * against `cert_url`: local verification means trusting a certificate URL
     * supplied by the request being verified. Fails CLOSED on any error.
     *
     * @param array<string, array<int, string>|string> $headers
     * @return string|null the mode it verified against
     */
    public function verifyWebhook(array $headers, string $rawBody): ?string
    {
        $h = static function (string $name) use ($headers): string {
            foreach ($headers as $key => $value) {
                if (strcasecmp((string) $key, $name) === 0) {
                    return is_array($value) ? (string) reset($value) : (string) $value;
                }
            }

            return '';
        };

        if (! is_array(json_decode($rawBody, true))) {
            return null;
        }

        // Refuse the obvious fakes before spending PayPal calls on them.
        foreach (['PAYPAL-AUTH-ALGO', 'PAYPAL-CERT-URL', 'PAYPAL-TRANSMISSION-ID', 'PAYPAL-TRANSMISSION-SIG', 'PAYPAL-TRANSMISSION-TIME'] as $name) {
            if (trim($h($name)) === '') {
                return null;
            }
        }

        $cert = parse_url($h('PAYPAL-CERT-URL'));
        $host = strtolower((string) ($cert['host'] ?? ''));
        if (($cert['scheme'] ?? '') !== 'https' || ($host !== 'paypal.com' && ! str_ends_with($host, '.paypal.com'))) {
            return null;
        }

        // A delivery can come from either environment's webhook (a sandbox
        // test while the forum is live), so try each connected one.
        foreach (['live', 'sandbox'] as $mode) {
            if (! $this->isConnected($mode) || ($token = $this->token($mode)) === null) {
                continue;
            }

            try {
                $res = $this->http->post($this->base($mode).'/v1/notifications/verify-webhook-signature', [
                    'headers' => ['Authorization' => 'Bearer '.$token, 'Content-Type' => 'application/json'],
                    'body' => self::verificationBody([
                        'auth_algo' => $h('PAYPAL-AUTH-ALGO'),
                        'cert_url' => $h('PAYPAL-CERT-URL'),
                        'transmission_id' => $h('PAYPAL-TRANSMISSION-ID'),
                        'transmission_sig' => $h('PAYPAL-TRANSMISSION-SIG'),
                        'transmission_time' => $h('PAYPAL-TRANSMISSION-TIME'),
                        'webhook_id' => $this->setting('webhook_id', $mode),
                    ], $rawBody),
                    'timeout' => 15,
                    'http_errors' => false,
                ]);

                $body = json_decode((string) $res->getBody(), true);

                if (($body['verification_status'] ?? '') === 'SUCCESS') {
                    return $mode;
                }
            } catch (GuzzleException $e) {
                $this->log->error('[kiosk] PayPal webhook verification failed: '.$e->getMessage());
            }
        }

        return null;
    }

    /** What Kiosk acts on. */
    public const WEBHOOK_EVENTS = [
        'CHECKOUT.ORDER.APPROVED',
        'PAYMENT.CAPTURE.COMPLETED', 'PAYMENT.CAPTURE.DENIED', 'PAYMENT.CAPTURE.REFUNDED', 'PAYMENT.CAPTURE.REVERSED',
        'BILLING.SUBSCRIPTION.ACTIVATED', 'BILLING.SUBSCRIPTION.CANCELLED', 'BILLING.SUBSCRIPTION.SUSPENDED',
        'BILLING.SUBSCRIPTION.EXPIRED', 'BILLING.SUBSCRIPTION.PAYMENT.FAILED', 'BILLING.SUBSCRIPTION.UPDATED',
        'PAYMENT.SALE.COMPLETED', 'PAYMENT.SALE.REFUNDED', 'PAYMENT.SALE.REVERSED',
    ];

    /**
     * Test the keys, then find this forum's webhook on the PayPal app or make
     * it, and save its id: two pasted fields and one click is the whole setup.
     *
     * @return array{ok: bool, mode: string, step?: string, error?: string, created?: bool, webhook_id?: string}
     */
    public function connect(string $webhookUrl, string $mode): array
    {
        unset($this->tokens[$mode]);
        $this->rejected = false;

        if (! $this->hasCredentials($mode) || $this->token($mode) === null) {
            return ['ok' => false, 'mode' => $mode, 'step' => $this->wasRejected() ? 'credentials' : 'unreachable'];
        }

        $list = $this->call('GET', '/v1/notifications/webhooks', null, null, $mode);

        if ($list === null) {
            return ['ok' => false, 'mode' => $mode, 'step' => 'unreachable'];
        }

        foreach ($list['webhooks'] ?? [] as $hook) {
            if (rtrim((string) ($hook['url'] ?? ''), '/') !== rtrim($webhookUrl, '/')) {
                continue;
            }

            $have = array_column($hook['event_types'] ?? [], 'name');
            if (array_diff(self::WEBHOOK_EVENTS, $have) && ! in_array('*', $have, true)) {
                $this->call('PATCH', '/v1/notifications/webhooks/'.rawurlencode((string) $hook['id']), [[
                    'op' => 'replace', 'path' => '/event_types',
                    'value' => array_map(fn ($n) => ['name' => $n], array_values(array_unique([...$have, ...self::WEBHOOK_EVENTS]))),
                ]], null, $mode);
            }

            $this->keepWebhook($mode, (string) $hook['id']);

            return ['ok' => true, 'mode' => $mode, 'created' => false, 'webhook_id' => (string) $hook['id']];
        }

        $made = $this->call('POST', '/v1/notifications/webhooks', [
            'url' => $webhookUrl,
            'event_types' => array_map(fn ($n) => ['name' => $n], self::WEBHOOK_EVENTS),
        ], null, $mode);

        if (! isset($made['id'])) {
            // PayPal refuses webhook URLs it cannot reach over HTTPS — the
            // usual cause on a forum still on http:// or localhost.
            return ['ok' => false, 'mode' => $mode, 'step' => 'webhook', 'error' => (string) ($made['details'][0]['description'] ?? $made['message'] ?? $made['name'] ?? '')];
        }

        $this->keepWebhook($mode, (string) $made['id']);

        return ['ok' => true, 'mode' => $mode, 'created' => true, 'webhook_id' => (string) $made['id']];
    }

    /** The forum's webhook id goes in settings; a member's goes back to the caller, which seals it. */
    private function keepWebhook(string $mode, string $id): void
    {
        if ($this->own === null) {
            $this->settings->set(self::PREFIX.$mode.'_webhook_id', $id);
        } else {
            $this->own['webhook_id'] = $id;
        }
    }
}
