<?php

namespace Ernestdefoe\Kiosk\Provider;

use Ernestdefoe\Kiosk\Model\Money;
use Ernestdefoe\Kiosk\Model\Price;
use Ernestdefoe\Kiosk\Model\Product;
use Ernestdefoe\Kiosk\Model\Purchase;

/**
 * BTCPay Server: the owner's own, self-hosted Bitcoin (and Lightning) checkout.
 * No middleman and no fees beyond the network's.
 *
 * Setup is one field: the server's address. Connect sends the admin to
 * BTCPay's own "authorize app" page; BTCPay posts back an API key limited to
 * creating and reading invoices and managing webhooks for the store they
 * pick, and Kiosk makes the webhook itself (Greenfield API).
 */
class BTCPay extends Crypto
{
    public const PERMISSIONS = ['btcpay.store.cancreateinvoice', 'btcpay.store.canviewinvoices', 'btcpay.store.webhooks.canmodifywebhooks'];

    public const EVENTS = ['InvoiceSettled', 'InvoiceProcessing', 'InvoiceExpired', 'InvoiceInvalid'];

    public function key(): string
    {
        return 'btcpay';
    }

    public function isReady(): bool
    {
        return $this->setting('url') !== '' && $this->setting('store_id') !== '' && $this->setting('api_secret') !== '' && $this->setting('webhook_secret') !== ''
            && (! $this->payee || $this->payee->isConnected());
    }

    /** BTCPay has no sandbox; a testnet store is the owner's choice. */
    public function mode(): string
    {
        return 'live';
    }

    public function url(): string
    {
        return rtrim($this->setting('url'), '/');
    }

    /** @return array<string, mixed>|null */
    public function api(string $method, string $path, ?array $json = null): ?array
    {
        return $this->request($method, $this->url().'/api/v1/stores/'.rawurlencode($this->setting('store_id')).$path, ['Authorization' => 'token '.$this->setting('api_secret')], $json);
    }

    public function checkout(Purchase $purchase, Price $price, Product $product, string $returnUrl, string $cancelUrl): string
    {
        $body = $this->api('POST', '/invoices', [
            'amount' => Money::toDecimal($purchase->amount_cents, $purchase->currency),
            'currency' => $purchase->currency,
            'metadata' => ['orderId' => $purchase->number, 'itemDesc' => mb_substr($purchase->product_name, 0, 200)],
            'checkout' => ['redirectURL' => $returnUrl, 'redirectAutomatically' => true],
        ]);

        if (! isset($body['id'], $body['checkoutLink']) || ($body['_status'] ?? 500) >= 400) {
            throw $this->unreachable($body);
        }

        $purchase->provider_ref = (string) $body['id'];
        $purchase->save();

        return (string) $body['checkoutLink'];
    }

    /** Ask BTCPay about the invoice; open access once it's settled. */
    public function confirm(Purchase $purchase): void
    {
        if ($purchase->status !== Purchase::PENDING || ! $purchase->provider_ref) {
            return;
        }

        $invoice = $this->api('GET', '/invoices/'.rawurlencode((string) $purchase->provider_ref));

        if (($invoice['status'] ?? '') === 'Settled' && ($invoice['metadata']['orderId'] ?? null) === $purchase->number) {
            $this->settle($purchase, 'btcpay-'.$invoice['id'], Money::toMinor($invoice['amount'] ?? null, $purchase->currency), (string) ($invoice['currency'] ?? ''));
        }
    }

    /** `BTCPay-Sig: sha256=<HMAC of the raw body with the webhook's secret>`. */
    public function verify(array $headers, string $rawBody): bool
    {
        $secret = $this->setting('webhook_secret');
        $sig = self::header($headers, 'BTCPay-Sig');

        return $secret !== '' && str_starts_with($sig, 'sha256=') && hash_equals(hash_hmac('sha256', $rawBody, $secret), substr($sig, 7));
    }

    public function eventId(array $event): string
    {
        return (string) ($event['deliveryId'] ?? '');
    }

    public function eventType(array $event): string
    {
        return (string) ($event['type'] ?? '');
    }

    /** The event only says "look again": the invoice is read from BTCPay itself. */
    public function handle(array $event): void
    {
        if (($event['type'] ?? '') !== 'InvoiceSettled' || ($event['storeId'] ?? null) !== $this->setting('store_id')) {
            return;
        }

        $purchase = $this->ownPurchases()->where('provider_ref', (string) ($event['invoiceId'] ?? ''))->first();

        if ($purchase) {
            $this->confirm($purchase);
        }
    }

    /** BTCPay's authorize page for this forum. */
    public function authorizeUrl(string $server, string $redirect): string
    {
        $query = http_build_query(['applicationName' => 'Kiosk (Flarum)', 'strict' => 'true', 'selectiveStores' => 'true', 'redirect' => $redirect]);
        foreach (self::PERMISSIONS as $permission) {
            $query .= '&permissions='.rawurlencode($permission);
        }

        return rtrim($server, '/').'/api-keys/authorize?'.$query;
    }

    /**
     * BTCPay posted the new key back: keep it, read the store it was limited
     * to from its permissions, and (re)make this forum's webhook.
     *
     * @param list<string> $permissions "btcpay.store.cancreateinvoice:<storeId>"
     */
    public function connect(string $server, string $apiKey, array $permissions, string $webhookUrl): bool
    {
        $stores = array_values(array_unique(array_filter(array_map(fn ($p) => explode(':', (string) $p, 2)[1] ?? null, $permissions))));

        if (count($stores) !== 1 || $apiKey === '') {
            return false;
        }

        $keep = ['url' => rtrim($server, '/'), 'store_id' => $stores[0], 'api_secret' => $apiKey, 'webhook_secret' => ''];
        $this->keep($keep);

        foreach ($this->api('GET', '/webhooks') ?? [] as $hook) {
            if (is_array($hook) && rtrim((string) ($hook['url'] ?? ''), '/') === rtrim($webhookUrl, '/')) {
                $this->api('DELETE', '/webhooks/'.rawurlencode((string) $hook['id']));
            }
        }

        $made = $this->api('POST', '/webhooks', [
            'url' => $webhookUrl,
            'enabled' => true,
            'automaticRedelivery' => true,
            'authorizedEvents' => ['everything' => false, 'specificEvents' => self::EVENTS],
        ]);

        if (! isset($made['id'], $made['secret'])) {
            return false;
        }

        $this->keep(['webhook_secret' => (string) $made['secret']] + $keep);

        return true;
    }

    /** The forum's store goes in settings; a member's is sealed into their account. @param array<string, string> $values */
    private function keep(array $values): void
    {
        if ($this->payee) {
            $this->payee->setCredentials($values);
            $this->payee->external_id = $values['store_id'];
            $this->payee->status = $values['webhook_secret'] !== '' ? \Ernestdefoe\Kiosk\Seller\Account::CONNECTED : \Ernestdefoe\Kiosk\Seller\Account::PENDING;
            $this->payee->save();

            return;
        }

        foreach ($values as $key => $value) {
            $this->settings->set('ernestdefoe-kiosk.btcpay_'.$key, $value);
        }
    }
}
