<?php

namespace Ernestdefoe\Kiosk\Api\Admin;

use Ernestdefoe\Kiosk\Api\Concerns;
use Ernestdefoe\Kiosk\Provider\PayPalClient;
use Flarum\Http\UrlGenerator;
use Flarum\Settings\SettingsRepositoryInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /kiosk/admin/paypal {mode, clientId?, secret?, use?}
 *
 * Saves the keys for a mode, checks them with PayPal and creates the webhook,
 * in one click. `use: true` also switches the forum to that mode. A secret
 * left blank keeps the one already saved, so the form never has to show it.
 */
class PayPalController implements RequestHandlerInterface
{
    use Concerns;

    public function __construct(private PayPalClient $paypal, private SettingsRepositoryInterface $settings, private UrlGenerator $url)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->admin($request);
        $body = $this->body($request);
        $mode = ($body['mode'] ?? '') === 'live' ? 'live' : 'sandbox';
        $prefix = 'ernestdefoe-kiosk.paypal_'.$mode.'_';

        if (isset($body['clientId'])) {
            $id = trim((string) $body['clientId']);
            if ($id !== $this->paypal->setting('client_id', $mode)) {
                // New keys may belong to another PayPal app: its webhook is not ours.
                $this->settings->delete($prefix.'webhook_id');
            }
            $this->settings->set($prefix.'client_id', $id);
        }
        if (isset($body['secret']) && trim((string) $body['secret']) !== '') {
            $this->settings->set($prefix.'secret', trim((string) $body['secret']));
            $this->settings->delete($prefix.'webhook_id');
        }

        $result = $this->paypal->connect($this->url->to('api')->route('kiosk.webhooks', ['provider' => 'paypal']), $mode);

        if ($result['ok'] && ! empty($body['use'])) {
            $this->settings->set('ernestdefoe-kiosk.paypal_mode', $mode);
        }

        return new JsonResponse(['data' => $result + ['status' => OverviewController::paypalStatus($this->paypal, $this->settings)]]);
    }
}
