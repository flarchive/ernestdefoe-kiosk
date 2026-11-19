<?php

namespace Ernestdefoe\Kiosk\Api\Admin;

use Ernestdefoe\Kiosk\Api\Concerns;
use Ernestdefoe\Kiosk\Provider\StripeClient;
use Flarum\Foundation\ValidationException;
use Flarum\Http\UrlGenerator;
use Flarum\Locale\TranslatorInterface;
use Flarum\Settings\SettingsRepositoryInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /kiosk/admin/stripe {key?, mode?, use?}
 *
 * One pasted secret key is the whole setup: its prefix says test or live,
 * and Connect checks it and makes the webhook. With no key, reconnects the
 * given mode with the key already saved. `use: true` switches the forum to
 * that mode.
 */
class StripeController implements RequestHandlerInterface
{
    use Concerns;

    public function __construct(
        private StripeClient $stripe,
        private SettingsRepositoryInterface $settings,
        private UrlGenerator $url,
        private TranslatorInterface $translator,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->admin($request);
        $body = $this->body($request);
        $key = trim((string) ($body['key'] ?? ''));

        // Members' sales: the Connect endpoint, in the mode the forum is using.
        if (! empty($body['sellers'])) {
            $result = $this->stripe->connectSellers($this->url->to('api')->route('kiosk.webhooks', ['provider' => 'stripe']), $this->stripe->mode());

            return new JsonResponse(['data' => $result + ['status' => self::status($this->stripe, $this->settings)]]);
        }

        if ($key !== '') {
            $mode = StripeClient::modeOfKey($key);
            if ($mode === null) {
                throw new ValidationException(['key' => $this->translator->trans('ernestdefoe-kiosk.admin.errors.stripe_key')]);
            }
            $this->settings->set("ernestdefoe-kiosk.stripe_{$mode}_secret", $key);
        } else {
            $mode = ($body['mode'] ?? '') === 'live' ? 'live' : 'test';
        }

        $result = $this->stripe->connect($this->url->to('api')->route('kiosk.webhooks', ['provider' => 'stripe']), $mode);

        // The first key connected is the one in use; after that, switching is explicit.
        if ($result['ok'] && (! empty($body['use']) || ! $this->settings->get('ernestdefoe-kiosk.stripe_mode'))) {
            $this->settings->set('ernestdefoe-kiosk.stripe_mode', $mode);
        }

        return new JsonResponse(['data' => $result + ['status' => self::status($this->stripe, $this->settings)]]);
    }

    /** @return array<string, mixed> */
    public static function status(StripeClient $stripe, SettingsRepositoryInterface $settings): array
    {
        $out = ['mode' => $stripe->mode(), 'lastWebhookAt' => $settings->get('ernestdefoe-kiosk.stripe_last_webhook_at') ?: null];

        foreach (['test', 'live'] as $mode) {
            $key = $stripe->setting('secret', $mode);
            $out[$mode] = [
                // The last four characters only, so the admin can tell keys apart.
                'keyHint' => $key !== '' ? '…'.substr($key, -4) : null,
                'connected' => $stripe->isConnected($mode),
                // Members can be paid through Stripe (Connect endpoint made).
                'sellers' => $stripe->setting('connect_webhook_secret', $mode) !== '',
            ];
        }

        return $out;
    }
}
