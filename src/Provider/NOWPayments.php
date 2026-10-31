<?php

namespace Ernestdefoe\Kiosk\Provider;

use Ernestdefoe\Kiosk\Model\Money;
use Ernestdefoe\Kiosk\Model\Price;
use Ernestdefoe\Kiosk\Model\Product;
use Ernestdefoe\Kiosk\Model\Purchase;

/**
 * NOWPayments: a hosted gateway taking 300+ coins, for owners who don't run
 * their own BTCPay Server. Two pasted values: the API key and the IPN secret
 * (both from its dashboard's Store settings).
 *
 * NOWPayments tells Kiosk about payments by IPN, signed with HMAC-SHA512 of
 * the body's keys in sorted order. Kiosk then asks it about the payment
 * before opening anything.
 */
class NOWPayments extends Crypto
{
    public function key(): string
    {
        return 'nowpayments';
    }

    public function isReady(): bool
    {
        return $this->setting('api_secret') !== '' && $this->setting('ipn_secret') !== '' && (! $this->payee || $this->payee->isConnected());
    }

    public function mode(): string
    {
        if ($this->payee) {
            return $this->payee->mode === 'sandbox' ? 'sandbox' : 'live';
        }

        return $this->settings->get('ernestdefoe-kiosk.nowpayments_sandbox') ? 'sandbox' : 'live';
    }

    private function base(?string $mode = null): string
    {
        return ($mode ?? $this->mode()) === 'sandbox' ? 'https://api-sandbox.nowpayments.io/v1' : 'https://api.nowpayments.io/v1';
    }

    /** @return array<string, mixed>|null */
    public function api(string $method, string $path, ?array $json = null, ?string $mode = null): ?array
    {
        return $this->request($method, $this->base($mode).$path, ['x-api-key' => $this->setting('api_secret')], $json);
    }

    public function checkout(Purchase $purchase, Price $price, Product $product, string $returnUrl, string $cancelUrl): string
    {
        $body = $this->api('POST', '/invoice', [
            'price_amount' => (float) Money::toDecimal($purchase->amount_cents, $purchase->currency),
            'price_currency' => strtolower($purchase->currency),
            'order_id' => $purchase->number,
            'order_description' => mb_substr($purchase->product_name, 0, 200),
            'ipn_callback_url' => $this->webhookUrl(),
            'success_url' => $returnUrl,
            'cancel_url' => $cancelUrl,
        ]);

        if (! isset($body['id'], $body['invoice_url']) || ($body['_status'] ?? 500) >= 400) {
            throw $this->unreachable($body);
        }

        $purchase->provider_ref = (string) $body['id'];
        $purchase->save();

        return (string) $body['invoice_url'];
    }

    /** NOWPayments has no invoice lookup by API key: the IPN is how a payment arrives. */
    public function confirm(Purchase $purchase): void
    {
    }

    public function verify(array $headers, string $rawBody): bool
    {
        $secret = $this->setting('ipn_secret');
        $data = json_decode($rawBody, true);

        if ($secret === '' || ! is_array($data)) {
            return false;
        }

        return hash_equals(hash_hmac('sha512', (string) json_encode(self::sorted($data), JSON_UNESCAPED_SLASHES), $secret), self::header($headers, 'x-nowpayments-sig'));
    }

    /** @param array<mixed> $data @return array<mixed> */
    private static function sorted(array $data): array
    {
        ksort($data);

        return array_map(fn ($v) => is_array($v) ? self::sorted($v) : $v, $data);
    }

    /** One event per payment status, so "confirming" then "finished" are both kept. */
    public function eventId(array $event): string
    {
        return isset($event['payment_id']) ? $event['payment_id'].'-'.($event['payment_status'] ?? '') : '';
    }

    public function eventType(array $event): string
    {
        return 'payment.'.($event['payment_status'] ?? '');
    }

    public function handle(array $event): void
    {
        if (($event['payment_status'] ?? '') !== 'finished' || ! ($purchase = $this->byNumber($event['order_id'] ?? null))) {
            return;
        }

        // The IPN is signed, but read the payment from NOWPayments all the same.
        $payment = $this->api('GET', '/payment/'.rawurlencode((string) $event['payment_id']), null, $purchase->mode);

        if (($payment['payment_status'] ?? '') === 'finished' && ($payment['order_id'] ?? null) === $purchase->number) {
            $this->settle($purchase, 'nowpayments-'.$payment['payment_id'], Money::toMinor($payment['price_amount'] ?? null, $purchase->currency), (string) ($payment['price_currency'] ?? ''));
        }
    }

    /** Check a key works: NOWPayments answers its coin list only to a real key. */
    public function check(string $mode): bool
    {
        return ($this->api('GET', '/merchant/coins', null, $mode)['_status'] ?? 500) === 200;
    }
}
