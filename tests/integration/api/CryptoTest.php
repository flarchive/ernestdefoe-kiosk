<?php

namespace Ernestdefoe\Kiosk\Tests\integration\api;

use Ernestdefoe\Kiosk\Tests\integration\KioskTestCase;
use Laminas\Diactoros\StreamFactory;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;

/** BTCPay Server and NOWPayments. */
class CryptoTest extends KioskTestCase
{
    private function btcpay(): void
    {
        $this->setting('ernestdefoe-kiosk.btcpay_url', 'https://btcpay.test');
        $this->setting('ernestdefoe-kiosk.btcpay_store_id', 'STORE1');
        $this->setting('ernestdefoe-kiosk.btcpay_api_secret', 'apikey');
        $this->setting('ernestdefoe-kiosk.btcpay_webhook_secret', 'hooksecret');
    }

    private function gold(string $kind = 'one_off'): array
    {
        return $this->product([['type' => 'group', 'targetId' => self::GOLD]], [['kind' => $kind, 'amount' => '5.00', 'currency' => 'USD', 'interval' => 'month']]);
    }

    private function inGold(int $userId = self::BUYER): bool
    {
        return $this->db()->table('group_user')->where('user_id', $userId)->where('group_id', self::GOLD)->exists();
    }

    /** @param array<string, mixed> $event */
    private function hook(string $provider, array $event, string $header, string $signature): ResponseInterface
    {
        $this->boot();
        $raw = (string) json_encode($event);

        return $this->send($this->request('POST', '/api/kiosk/webhooks/'.$provider, ['json' => $event])
            ->withBody((new StreamFactory())->createStream($raw))
            ->withHeader($header, $signature));
    }

    #[Test]
    public function btcpay_opens_access_once_btcpay_itself_says_the_invoice_is_settled(): void
    {
        $this->btcpay();
        $product = $this->gold();
        $this->crypto->answers['POST /api/v1/stores/STORE1/invoices'] = [200, ['id' => 'INV1', 'checkoutLink' => 'https://btcpay.test/i/INV1']];

        $start = $this->json($this->api('POST', '/kiosk/checkout', self::BUYER, ['priceId' => $product['prices'][0]['id'], 'provider' => 'btcpay']));
        $this->assertSame('https://btcpay.test/i/INV1', $start['data']['url']);
        $sent = collect($this->crypto->calls)->firstWhere('path', '/api/v1/stores/STORE1/invoices');
        $this->assertSame('5.00', $sent['body']['amount']);
        $this->assertSame(['token apikey'], $sent['headers']['Authorization']);

        $event = ['deliveryId' => 'D1', 'type' => 'InvoiceSettled', 'storeId' => 'STORE1', 'invoiceId' => 'INV1'];

        // A forged delivery is refused before anything is read.
        $this->assertSame(400, $this->hook('btcpay', $event, 'BTCPay-Sig', 'sha256=forged')->getStatusCode());

        // Signed, but BTCPay says it isn't settled yet: nothing opens.
        $this->crypto->answers['GET /api/v1/stores/STORE1/invoices/INV1'] = [200, ['id' => 'INV1', 'status' => 'Processing', 'amount' => '5.00', 'currency' => 'USD', 'metadata' => ['orderId' => $start['data']['number']]]];
        $sig = 'sha256='.hash_hmac('sha256', (string) json_encode($event), 'hooksecret');
        $this->assertSame(200, $this->hook('btcpay', $event, 'BTCPay-Sig', $sig)->getStatusCode());
        $this->assertFalse($this->inGold());

        $this->crypto->answers['GET /api/v1/stores/STORE1/invoices/INV1'][1]['status'] = 'Settled';
        $event['deliveryId'] = 'D2';
        $this->hook('btcpay', $event, 'BTCPay-Sig', 'sha256='.hash_hmac('sha256', (string) json_encode($event), 'hooksecret'));
        $this->assertTrue($this->inGold());
        $this->assertSame(500, (int) $this->db()->table('kiosk_payments')->value('amount_cents'));
    }

    #[Test]
    public function a_settled_invoice_for_less_opens_nothing(): void
    {
        $this->btcpay();
        $product = $this->gold();
        $this->crypto->answers['POST /api/v1/stores/STORE1/invoices'] = [200, ['id' => 'INV1', 'checkoutLink' => 'https://btcpay.test/i/INV1']];
        $number = $this->json($this->api('POST', '/kiosk/checkout', self::BUYER, ['priceId' => $product['prices'][0]['id'], 'provider' => 'btcpay']))['data']['number'];

        $this->crypto->answers['GET /api/v1/stores/STORE1/invoices/INV1'] = [200, ['id' => 'INV1', 'status' => 'Settled', 'amount' => '0.50', 'currency' => 'USD', 'metadata' => ['orderId' => $number]]];
        $this->api('POST', '/kiosk/purchases/'.$number.'/confirm', self::BUYER);

        $this->assertFalse($this->inGold());
        $this->assertSame('pending', $this->db()->table('kiosk_purchases')->value('status'));
    }

    #[Test]
    public function crypto_never_takes_a_subscription(): void
    {
        $this->btcpay();
        $monthly = $this->gold('recurring');

        $this->assertSame(422, $this->api('POST', '/kiosk/checkout', self::BUYER, ['priceId' => $monthly['prices'][0]['id'], 'provider' => 'btcpay'])->getStatusCode());
        $providers = $this->json($this->api('GET', '/kiosk/store', self::BUYER))['meta']['providers'];
        $this->assertFalse(collect($providers)->firstWhere('key', 'btcpay')['recurring']);
        $this->assertTrue(collect($providers)->firstWhere('key', 'paypal')['recurring']);
    }

    #[Test]
    public function connecting_btcpay_takes_the_key_it_posts_back_once_and_makes_the_webhook(): void
    {
        $url = $this->json($this->api('POST', '/kiosk/admin/btcpay', 1, ['url' => 'https://btcpay.test']))['data']['url'];
        $this->assertStringStartsWith('https://btcpay.test/api-keys/authorize?', $url);
        $this->assertStringContainsString('permissions=btcpay.store.cancreateinvoice', $url);
        preg_match('/state=([A-Za-z0-9]+)/', urldecode($url), $m);
        $state = $m[1];

        $this->crypto->answers['GET /api/v1/stores/STORE9/webhooks'] = [200, []];
        $this->crypto->answers['POST /api/v1/stores/STORE9/webhooks'] = [200, ['id' => 'WH9', 'secret' => 'sekrit']];

        $back = fn () => $this->send($this->request('POST', '/api/kiosk/btcpay/authorized')
            ->withQueryParams(['state' => $state])
            ->withParsedBody(['apiKey' => 'NEWKEY', 'permissions' => ['btcpay.store.cancreateinvoice:STORE9', 'btcpay.store.canviewinvoices:STORE9', 'btcpay.store.webhooks.canmodifywebhooks:STORE9']]));

        $this->boot();
        $response = $back();
        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('btcpay=connected', $response->getHeaderLine('Location'));
        $this->assertSame('STORE9', $this->db()->table('settings')->where('key', 'ernestdefoe-kiosk.btcpay_store_id')->value('value'));
        $this->assertSame('sekrit', $this->db()->table('settings')->where('key', 'ernestdefoe-kiosk.btcpay_webhook_secret')->value('value'));

        // The state works once.
        $this->boot();
        $this->assertStringContainsString('btcpay=expired', $back()->getHeaderLine('Location'));

        // https only, and admins only.
        $this->assertSame(422, $this->api('POST', '/kiosk/admin/btcpay', 1, ['url' => 'http://btcpay.test'])->getStatusCode());
        $this->assertSame(403, $this->api('POST', '/kiosk/admin/btcpay', self::BUYER, ['url' => 'https://btcpay.test'])->getStatusCode());
    }

    #[Test]
    public function nowpayments_opens_access_on_a_signed_finished_ipn_it_confirms(): void
    {
        $this->setting('ernestdefoe-kiosk.nowpayments_api_secret', 'npkey');
        $this->setting('ernestdefoe-kiosk.nowpayments_ipn_secret', 'ipnsecret');
        $product = $this->gold();
        $this->crypto->answers['POST /v1/invoice'] = [200, ['id' => '4522', 'invoice_url' => 'https://nowpayments.io/payment/?iid=4522']];

        $number = $this->json($this->api('POST', '/kiosk/checkout', self::BUYER, ['priceId' => $product['prices'][0]['id'], 'provider' => 'nowpayments']))['data']['number'];
        $invoice = collect($this->crypto->calls)->firstWhere('path', '/v1/invoice');
        $this->assertSame('api.nowpayments.io', $invoice['host']);
        $this->assertSame($number, $invoice['body']['order_id']);

        $ipn = ['payment_status' => 'finished', 'payment_id' => 777, 'order_id' => $number, 'price_amount' => 5, 'price_currency' => 'usd', 'pay_currency' => 'btc'];
        $sorted = $ipn;
        ksort($sorted);
        $sig = hash_hmac('sha512', (string) json_encode($sorted, JSON_UNESCAPED_SLASHES), 'ipnsecret');

        $this->assertSame(400, $this->hook('nowpayments', $ipn, 'x-nowpayments-sig', 'forged')->getStatusCode());

        $this->crypto->answers['GET /v1/payment/777'] = [200, ['payment_id' => 777, 'payment_status' => 'finished', 'order_id' => $number, 'price_amount' => 5, 'price_currency' => 'usd']];
        $this->assertSame(200, $this->hook('nowpayments', $ipn, 'x-nowpayments-sig', $sig)->getStatusCode());
        $this->assertTrue($this->inGold());
    }
}
