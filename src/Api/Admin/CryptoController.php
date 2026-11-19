<?php

namespace Ernestdefoe\Kiosk\Api\Admin;

use Ernestdefoe\Kiosk\Api\Concerns;
use Ernestdefoe\Kiosk\Provider\BTCPay;
use Ernestdefoe\Kiosk\Provider\NOWPayments;
use Flarum\Foundation\ValidationException;
use Flarum\Http\UrlGenerator;
use Flarum\Locale\TranslatorInterface;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Support\Str;
use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /kiosk/admin/btcpay {url}            → BTCPay's authorize page to go to
 * POST /kiosk/btcpay/authorized?state=…      ← BTCPay posts the new key back
 * POST /kiosk/admin/nowpayments {apiKey, ipnSecret, sandbox}
 * POST /kiosk/admin/{btcpay|nowpayments}/disconnect
 */
class CryptoController implements RequestHandlerInterface
{
    use Concerns;

    public function __construct(
        private BTCPay $btcpay,
        private NOWPayments $nowpayments,
        private SettingsRepositoryInterface $settings,
        private UrlGenerator $url,
        private TranslatorInterface $translator,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $route = (string) $request->getAttribute('routeName');

        if ($route === 'kiosk.btcpay.authorized') {
            return $this->authorized($request);
        }

        $this->admin($request);
        $body = $this->body($request);

        if (str_ends_with($route, '.disconnect')) {
            $key = str_contains($route, 'btcpay') ? 'btcpay' : 'nowpayments';
            $keys = $key === 'btcpay' ? ['url', 'store_id', 'api_secret', 'webhook_secret'] : ['api_secret', 'ipn_secret'];
            foreach ($keys as $k) {
                $this->settings->delete("ernestdefoe-kiosk.{$key}_$k");
            }

            return new JsonResponse(['data' => self::status($this->btcpay, $this->nowpayments, $this->settings)]);
        }

        if ($route === 'kiosk.admin.btcpay') {
            $server = rtrim(trim((string) ($body['url'] ?? '')), '/');
            // 🚨 https only: the API key and every invoice go over it.
            if (! preg_match('~^https://[^/\s?#]+(/[^\s?#]*)?$~i', $server)) {
                throw new ValidationException(['url' => $this->translator->trans('ernestdefoe-kiosk.admin.errors.btcpay_url')]);
            }

            // BTCPay posts back without our session cookie (a cross-site
            // POST), so a one-time state stands in for it.
            $state = Str::random(40);
            $this->settings->set('ernestdefoe-kiosk.btcpay_state_secret', $state.'|'.(time() + 900).'|'.$server);

            return new JsonResponse(['data' => ['url' => $this->btcpay->authorizeUrl($server, $this->url->to('api')->route('kiosk.btcpay.authorized').'?state='.$state)]]);
        }

        // NOWPayments: paste, check, save.
        $sandbox = ! empty($body['sandbox']);
        $apiKey = trim((string) ($body['apiKey'] ?? ''));
        $ipn = trim((string) ($body['ipnSecret'] ?? ''));
        if ($apiKey === '' || $ipn === '') {
            throw new ValidationException(['apiKey' => $this->translator->trans('ernestdefoe-kiosk.admin.errors.nowpayments_keys')]);
        }

        $old = [$this->settings->get('ernestdefoe-kiosk.nowpayments_api_secret'), $this->settings->get('ernestdefoe-kiosk.nowpayments_sandbox')];
        $this->settings->set('ernestdefoe-kiosk.nowpayments_api_secret', $apiKey);
        $this->settings->set('ernestdefoe-kiosk.nowpayments_sandbox', $sandbox ? '1' : '');

        if (! $this->nowpayments->check($sandbox ? 'sandbox' : 'live')) {
            $this->settings->set('ernestdefoe-kiosk.nowpayments_api_secret', (string) $old[0]);
            $this->settings->set('ernestdefoe-kiosk.nowpayments_sandbox', (string) $old[1]);

            throw new ValidationException(['apiKey' => $this->translator->trans('ernestdefoe-kiosk.admin.errors.nowpayments_rejected')]);
        }

        $this->settings->set('ernestdefoe-kiosk.nowpayments_ipn_secret', $ipn);

        return new JsonResponse(['data' => self::status($this->btcpay, $this->nowpayments, $this->settings)]);
    }

    private function authorized(ServerRequestInterface $request): ResponseInterface
    {
        $admin = $this->url->to('admin')->base().'#/extension/ernestdefoe-kiosk?tab=payments';
        [$state, $expires, $server] = array_pad(explode('|', (string) $this->settings->get('ernestdefoe-kiosk.btcpay_state_secret'), 3), 3, '');
        $given = (string) ($request->getQueryParams()['state'] ?? '');

        // Single use, fifteen minutes. A wrong state leaves the right one
        // alone, so a stranger can't spoil the admin's connect.
        if ($state === '' || ! hash_equals($state, $given)) {
            return new RedirectResponse($admin.'&btcpay=expired');
        }
        $this->settings->delete('ernestdefoe-kiosk.btcpay_state_secret');
        if ((int) $expires < time()) {
            return new RedirectResponse($admin.'&btcpay=expired');
        }

        $form = (array) $request->getParsedBody();
        $ok = $this->btcpay->connect(
            $server,
            trim((string) ($form['apiKey'] ?? '')),
            array_values(array_map('strval', (array) ($form['permissions'] ?? []))),
            $this->url->to('api')->route('kiosk.webhooks', ['provider' => 'btcpay']),
        );

        return new RedirectResponse($admin.'&btcpay='.($ok ? 'connected' : 'failed'));
    }

    /** @return array<string, mixed> */
    public static function status(BTCPay $btcpay, NOWPayments $nowpayments, SettingsRepositoryInterface $settings): array
    {
        $key = (string) $settings->get('ernestdefoe-kiosk.nowpayments_api_secret');

        return [
            'btcpay' => [
                'connected' => $btcpay->isReady(),
                'url' => $btcpay->url() ?: null,
                'storeId' => $settings->get('ernestdefoe-kiosk.btcpay_store_id') ?: null,
                'lastWebhookAt' => $settings->get('ernestdefoe-kiosk.btcpay_last_webhook_at') ?: null,
            ],
            'nowpayments' => [
                'connected' => $nowpayments->isReady(),
                'sandbox' => (bool) $settings->get('ernestdefoe-kiosk.nowpayments_sandbox'),
                'keyHint' => $key !== '' ? '…'.substr($key, -4) : null,
                'lastWebhookAt' => $settings->get('ernestdefoe-kiosk.nowpayments_last_webhook_at') ?: null,
            ],
        ];
    }
}
