<?php

namespace Ernestdefoe\Kiosk\Provider;

use Carbon\Carbon;
use Ernestdefoe\Kiosk\Model\Payment;
use Ernestdefoe\Kiosk\Model\Price;
use Ernestdefoe\Kiosk\Model\Purchase;
use Ernestdefoe\Kiosk\Service\Fulfilment;
use Flarum\Foundation\ValidationException;
use Flarum\Locale\TranslatorInterface;
use Flarum\Settings\SettingsRepositoryInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

/**
 * What the crypto gateways share. A crypto payment is pushed by the buyer
 * and can't be pulled again, so these sell one-time and set-time prices
 * only (a subscription would have nobody to bill), and a refund is sent
 * from the gateway's own dashboard.
 *
 * 🚨 As with the card providers: the return page is never believed, the
 * gateway is asked again before access opens, and amounts are compared.
 */
abstract class Crypto implements Provider, WebhookProvider
{
    public function __construct(
        protected Client $http,
        protected SettingsRepositoryInterface $settings,
        protected Fulfilment $fulfilment,
        protected TranslatorInterface $translator,
        protected LoggerInterface $log,
    ) {
    }

    protected function setting(string $key): string
    {
        return trim((string) $this->settings->get('ernestdefoe-kiosk.'.$this->key().'_'.$key));
    }

    public function supports(Price $price): bool
    {
        return ! $price->isRecurring();
    }

    public function cancel(Purchase $purchase): bool
    {
        return false;
    }

    public function refund(Payment $payment): bool
    {
        return false;
    }

    public function switchPlan(Purchase $purchase, Price $to, string $returnUrl): ?string
    {
        throw new ValidationException(['purchase' => $this->translator->trans('ernestdefoe-kiosk.forum.errors.cannot_switch')]);
    }

    public function reconcile(Purchase $purchase): void
    {
        $this->confirm($purchase);
    }

    /**
     * The gateway says this is paid in full: record it and open access, once.
     * A wrong amount or currency opens nothing and is logged for the admin.
     */
    protected function settle(Purchase $purchase, string $paymentId, ?int $amount, string $currency): void
    {
        if ($purchase->status !== Purchase::PENDING) {
            return;
        }
        if ($amount !== $purchase->amount_cents || strcasecmp($currency, $purchase->currency) !== 0) {
            $this->log->error('[kiosk] '.$this->key().' paid '.($amount ?? '?').' '.$currency.' for purchase '.$purchase->number.', which costs '.$purchase->amount_cents.' '.$purchase->currency);

            return;
        }

        $this->fulfilment->recordPayment($purchase, $paymentId, $amount, $purchase->currency);
        $now = Carbon::now();
        $this->fulfilment->activate($purchase, $now, $purchase->periodEndFrom($now));
    }

    /**
     * One JSON call. The decoded body with `_status`, or null when the
     * gateway could not be reached.
     *
     * @param array<string, string> $headers
     * @param array<string, mixed>|null $json
     * @return array<string, mixed>|null
     */
    protected function request(string $method, string $url, array $headers, ?array $json = null): ?array
    {
        try {
            $res = $this->http->request($method, $url, array_filter([
                'headers' => $headers + ['Accept' => 'application/json'],
                'json' => $json,
                'timeout' => 20,
                'http_errors' => false,
            ], fn ($v) => $v !== null));
        } catch (GuzzleException $e) {
            $this->log->error('[kiosk] '.$this->key()." $method $url failed: ".$e->getMessage());

            return null;
        }

        $body = json_decode((string) $res->getBody(), true);
        $body = is_array($body) ? $body : [];
        $body['_status'] = $res->getStatusCode();

        if ($res->getStatusCode() >= 400) {
            $this->log->warning('[kiosk] '.$this->key()." $method $url answered ".$res->getStatusCode());
        }

        return $body;
    }

    protected function unreachable(?array $body): ValidationException
    {
        return new ValidationException(['provider' => $this->translator->trans(
            $body === null ? 'ernestdefoe-kiosk.forum.errors.provider_unreachable' : 'ernestdefoe-kiosk.forum.errors.provider_refused'
        )]);
    }

    /** @param array<string, array<int, string>|string> $headers */
    protected static function header(array $headers, string $name): string
    {
        foreach ($headers as $key => $value) {
            if (strcasecmp((string) $key, $name) === 0) {
                return is_array($value) ? (string) reset($value) : (string) $value;
            }
        }

        return '';
    }

    protected function byNumber(mixed $number): ?Purchase
    {
        return is_string($number) && $number !== '' ? Purchase::query()->where('number', $number)->where('provider', $this->key())->first() : null;
    }
}
