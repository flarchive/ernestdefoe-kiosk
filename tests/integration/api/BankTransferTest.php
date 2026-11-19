<?php

namespace Ernestdefoe\Kiosk\Tests\integration\api;

use Carbon\Carbon;
use Ernestdefoe\Kiosk\Extend\UnlockType;
use Ernestdefoe\Kiosk\Service\Sweeper;
use Ernestdefoe\Kiosk\Tests\integration\Fixtures\TestItemUnlock;
use Ernestdefoe\Kiosk\Tests\integration\KioskTestCase;
use Laminas\Diactoros\StreamFactory;
use Laminas\Diactoros\UploadedFile;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;

/**
 * Manual bank transfer: the order waits, the admin (or the seller) marks it
 * paid, and it opens through the same fulfilment as every other payment.
 * Member 2 (NORMAL) is a bystander, or the seller where one is needed.
 */
class BankTransferTest extends KioskTestCase
{
    private const NORMAL = 2;

    /** Fake test details: nothing here is a real account. */
    private const DETAILS = "Example Forum Ltd\nTest Bank\nIBAN XX00 TEST 0000 0000 0000";

    protected function setUp(): void
    {
        parent::setUp();
        $this->setting('ernestdefoe-kiosk.bank_enabled', '1');
        $this->setting('ernestdefoe-kiosk.bank_details', self::DETAILS);
        $this->setting('ernestdefoe-kiosk.bank_instructions', 'Transfers take a day or two.');
    }

    private function gold(array $price = [], array $extra = []): array
    {
        return $this->product([['type' => 'group', 'targetId' => self::GOLD]], [$price + ['kind' => 'one_off', 'amount' => '12.50', 'currency' => 'EUR']], $extra);
    }

    /** @return array<string, mixed> the checkout's reply */
    private function order(array $product, int $as = self::BUYER, array $options = []): array
    {
        $response = $this->api('POST', '/kiosk/checkout', $as, ['priceId' => $product['prices'][0]['id'], 'provider' => 'bank'] + $options);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return $this->json($response)['data'];
    }

    private function inGold(int $userId = self::BUYER): bool
    {
        return $this->db()->table('group_user')->where('user_id', $userId)->where('group_id', self::GOLD)->exists();
    }

    private function statusOf(string $number): string
    {
        return (string) $this->db()->table('kiosk_purchases')->where('number', $number)->value('status');
    }

    private function proof(string $number, ?string $bytes, string $name = 'proof.png', string $note = '', int $as = self::BUYER): ResponseInterface
    {
        $this->boot();
        $request = $this->request('POST', '/api/kiosk/transfers/'.$number.'/proof', ['authenticatedAs' => $as])->withParsedBody(['note' => $note]);
        if ($bytes !== null) {
            $request = $request->withUploadedFiles(['proof' => new UploadedFile((new StreamFactory())->createStream($bytes), strlen($bytes), UPLOAD_ERR_OK, $name, 'image/png')]);
        }

        return $this->send($request);
    }

    private function png(): string
    {
        $image = imagecreatetruecolor(40, 20);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    #[Test]
    public function an_order_waits_with_its_reference_and_only_its_buyer_sees_the_bank_details(): void
    {
        $product = $this->gold();

        // Offered at checkout, but the details are nowhere in what the store or the forum send.
        $store = $this->api('GET', '/kiosk/store', self::BUYER);
        $this->assertContains('bank', array_column($this->json($store)['meta']['providers'], 'key'));
        foreach ([null, self::BUYER, self::NORMAL] as $who) {
            $this->assertStringNotContainsString('TEST 0000', (string) $this->api('GET', '/', $who)->getBody());
            $this->assertStringNotContainsString('TEST 0000', (string) $this->api('GET', '/kiosk/store', $who)->getBody());
        }

        $order = $this->order($product);
        // Off to the order page on the forum itself (no provider to visit).
        $this->assertStringEndsWith('/store/return/'.$order['number'], $order['url']);
        $this->assertSame('awaiting', $this->statusOf($order['number']));
        $this->assertFalse($this->inGold());

        $mine = $this->json($this->api('GET', '/kiosk/purchases/'.$order['number'], self::BUYER))['data'];
        $this->assertSame('awaiting', $mine['status']);
        $this->assertSame($order['number'], $mine['transfer']['reference']);
        $this->assertSame(self::DETAILS, $mine['transfer']['details']);
        $this->assertSame('Transfers take a day or two.', $mine['transfer']['instructions']);
        $this->assertNotNull($mine['transfer']['expiresAt']);
        // In their list too, still waiting.
        $this->assertSame('awaiting', $this->json($this->api('GET', '/kiosk/purchases', self::BUYER))['data'][0]['status']);

        // Nobody else can open it.
        $this->assertSame(404, $this->api('GET', '/kiosk/purchases/'.$order['number'], self::NORMAL)->getStatusCode());

        // The buyer was sent the details (by the notification, which keeps none of them itself).
        $note = $this->db()->table('notifications')->where('user_id', self::BUYER)->where('type', 'kioskPurchase')->first();
        $this->assertSame('awaiting', json_decode((string) $note->data, true)['kind']);
        $this->assertStringNotContainsString('TEST 0000', (string) $note->data);

        // The admin's queue has it, without the account in every row.
        $queue = $this->json($this->api('GET', '/kiosk/admin/transfers', 1))['data'];
        $this->assertSame($order['number'], $queue[0]['transfer']['reference']);
        $this->assertArrayNotHasKey('details', $queue[0]['transfer']);
        $this->assertSame(403, $this->api('GET', '/kiosk/admin/transfers', self::BUYER)->getStatusCode());
    }

    #[Test]
    public function only_an_admin_can_mark_it_paid_and_twice_opens_it_once(): void
    {
        $order = $this->order($this->gold());

        // The buyer marking their own order paid would be a free purchase.
        $this->assertSame(404, $this->api('POST', '/kiosk/transfers/'.$order['number'].'/paid', self::BUYER)->getStatusCode());
        $this->assertSame(404, $this->api('POST', '/kiosk/transfers/'.$order['number'].'/paid', self::NORMAL)->getStatusCode());
        $this->assertSame(404, $this->api('POST', '/kiosk/transfers/'.$order['number'].'/paid', self::STAFF)->getStatusCode());
        $this->assertFalse($this->inGold());

        $this->assertSame(204, $this->api('POST', '/kiosk/transfers/'.$order['number'].'/paid', 1)->getStatusCode());
        $this->assertSame(422, $this->api('POST', '/kiosk/transfers/'.$order['number'].'/paid', 1)->getStatusCode());

        $this->assertTrue($this->inGold());
        $this->assertSame('active', $this->statusOf($order['number']));
        $this->assertSame(1, $this->db()->table('kiosk_payments')->count());
        $this->assertSame(1250, (int) $this->db()->table('kiosk_payments')->value('amount_cents'));
        $this->assertSame(1, (int) $this->db()->table('kiosk_products')->value('sold_count'));
        // The ordinary receipt went out, as for any other payment.
        $kinds = $this->db()->table('notifications')->where('user_id', self::BUYER)->pluck('data')->map(fn ($d) => json_decode((string) $d, true)['kind'])->all();
        $this->assertContains('receipt', $kinds);

        // A paid order can't be cancelled as unpaid.
        $this->assertSame(422, $this->api('POST', '/kiosk/transfers/'.$order['number'].'/cancel', 1)->getStatusCode());
        $this->assertTrue($this->inGold());
    }

    #[Test]
    public function the_last_one_in_stock_is_held_for_the_transfer_and_freed_when_cancelled(): void
    {
        $product = $this->gold([], ['stock' => 1]);
        $order = $this->order($product);

        // Held: nobody else can take it meanwhile.
        $this->assertSame(422, $this->api('POST', '/kiosk/checkout', self::NORMAL, ['priceId' => $product['prices'][0]['id'], 'provider' => 'bank'])->getStatusCode());
        $this->assertSame('sold_out', $this->json($this->api('GET', '/kiosk/store', self::NORMAL))['data'][0]['refusal']);

        // The buyer calls it off: it's free again, and the buyer is told.
        $this->assertSame(200, $this->api('POST', '/kiosk/transfers/'.$order['number'].'/cancel', self::BUYER)->getStatusCode());
        $this->assertSame('expired', $this->statusOf($order['number']));
        $this->assertSame(0, (int) $this->db()->table('kiosk_products')->value('reserved_count'));
        $this->assertNull($this->json($this->api('GET', '/kiosk/store', self::NORMAL))['data'][0]['refusal']);

        // Marking a cancelled order paid opens nothing.
        $this->assertSame(422, $this->api('POST', '/kiosk/transfers/'.$order['number'].'/paid', 1)->getStatusCode());
        $this->assertFalse($this->inGold());

        // Paid, the hold becomes the sale: counted once, not twice.
        $second = $this->order($product, self::NORMAL);
        $this->api('POST', '/kiosk/transfers/'.$second['number'].'/paid', 1);
        $row = $this->db()->table('kiosk_products')->first();
        $this->assertSame([1, 0], [(int) $row->sold_count, (int) $row->reserved_count]);
    }

    #[Test]
    public function an_unpaid_order_lapses_after_the_days_set_and_gives_back_its_stock_and_coupon(): void
    {
        $this->setting('ernestdefoe-kiosk.bank_expiry_days', '3');
        $product = $this->gold([], ['stock' => 5]);
        $this->api('POST', '/kiosk/admin/coupons', 1, ['code' => 'TENOFF', 'kind' => 'percent', 'value' => 10, 'maxUses' => 1]);
        $order = $this->order($product, self::BUYER, ['coupon' => 'TENOFF']);

        $row = $this->db()->table('kiosk_purchases')->where('number', $order['number'])->first();
        $this->assertEqualsWithDelta(Carbon::now()->addDays(3)->getTimestamp(), Carbon::parse($row->expires_at)->getTimestamp(), 60);
        $this->assertSame(1, (int) $this->db()->table('kiosk_coupons')->value('used_count'));

        // Still waiting before its day: the sweep leaves it alone (no 3-hour abandonment).
        $this->db()->table('kiosk_purchases')->where('id', $row->id)->update(['created_at' => Carbon::now()->subDays(2)]);
        $this->app()->getContainer()->make(Sweeper::class)->run();
        $this->assertSame('awaiting', $this->statusOf($order['number']));

        $this->db()->table('kiosk_purchases')->where('id', $row->id)->update(['expires_at' => Carbon::now()->subMinute()]);
        $this->assertSame(1, $this->app()->getContainer()->make(Sweeper::class)->run()['transfers']);

        $this->assertSame('expired', $this->statusOf($order['number']));
        $this->assertSame(0, (int) $this->db()->table('kiosk_products')->value('reserved_count'));
        $this->assertSame(0, (int) $this->db()->table('kiosk_coupons')->value('used_count'));
        $kinds = $this->db()->table('notifications')->where('user_id', self::BUYER)->pluck('data')->map(fn ($d) => json_decode((string) $d, true)['kind'])->all();
        $this->assertContains('transfer_expired', $kinds);
    }

    #[Test]
    public function subscriptions_and_a_pile_of_unpaid_orders_are_refused(): void
    {
        $monthly = $this->product([['type' => 'group', 'targetId' => self::GOLD]], [['kind' => 'recurring', 'interval' => 'month', 'amount' => '5', 'currency' => 'EUR']]);
        $this->assertSame(422, $this->api('POST', '/kiosk/checkout', self::BUYER, ['priceId' => $monthly['prices'][0]['id'], 'provider' => 'bank'])->getStatusCode());

        $product = $this->gold();
        for ($i = 0; $i < 3; $i++) {
            $this->order($product);
        }
        $this->assertSame(422, $this->api('POST', '/kiosk/checkout', self::BUYER, ['priceId' => $product['prices'][0]['id'], 'provider' => 'bank'])->getStatusCode());
    }

    #[Test]
    public function without_account_details_it_is_not_offered(): void
    {
        $this->setting('ernestdefoe-kiosk.bank_details', '');
        $product = $this->gold();
        $this->assertNotContains('bank', array_column($this->json($this->api('GET', '/kiosk/store', self::NORMAL))['meta']['providers'], 'key'));
        $this->assertSame(422, $this->api('POST', '/kiosk/checkout', self::BUYER, ['priceId' => $product['prices'][0]['id'], 'provider' => 'bank'])->getStatusCode());
    }

    #[Test]
    public function a_proof_must_really_be_a_picture_or_pdf_and_only_buyer_and_admin_can_open_it(): void
    {
        $order = $this->order($this->gold());

        // A page dressed up as a picture or a PDF is refused.
        $this->assertSame(422, $this->proof($order['number'], '<html><script>alert(1)</script></html>', 'proof.png')->getStatusCode());
        $this->assertSame(422, $this->proof($order['number'], '<?php echo 1; ?>', 'proof.pdf')->getStatusCode());
        $this->assertSame(422, $this->proof($order['number'], null)->getStatusCode());
        // Too big.
        $this->assertSame(422, $this->proof($order['number'], '%PDF-1.4'.str_repeat('x', 5 * 1024 * 1024 + 1), 'big.pdf')->getStatusCode());
        // Someone else can't add one.
        $this->assertSame(404, $this->proof($order['number'], $this->png(), 'proof.png', '', self::NORMAL)->getStatusCode());

        $sent = $this->proof($order['number'], $this->png(), 'proof.png', 'Sent from my phone at 10am');
        $this->assertSame(200, $sent->getStatusCode(), (string) $sent->getBody());
        $this->assertSame('image', $this->json($sent)['data']['transfer']['proof']['file']);
        $this->assertSame('Sent from my phone at 10am', $this->json($sent)['data']['transfer']['proof']['note']);

        // Stored on the private disk, not under the public assets.
        $path = (string) $this->db()->table('kiosk_purchases')->where('number', $order['number'])->value('proof_path');
        $this->assertFileExists($this->app()->getContainer()->make(\Flarum\Foundation\Paths::class)->storage.'/kiosk/proofs/'.$path);

        $file = $this->api('GET', '/kiosk/transfers/'.$order['number'].'/proof', 1);
        $this->assertSame(200, $file->getStatusCode());
        $this->assertSame('image/png', $file->getHeaderLine('Content-Type'));
        $this->assertSame('nosniff', $file->getHeaderLine('X-Content-Type-Options'));
        $this->assertSame(200, $this->api('GET', '/kiosk/transfers/'.$order['number'].'/proof', self::BUYER)->getStatusCode());
        $this->assertSame(404, $this->api('GET', '/kiosk/transfers/'.$order['number'].'/proof', self::NORMAL)->getStatusCode());
        $this->assertSame(404, $this->api('GET', '/kiosk/transfers/'.$order['number'].'/proof', self::STAFF)->getStatusCode());

        // A real PDF is fine.
        $this->assertSame(200, $this->proof($order['number'], "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF", 'receipt.pdf')->getStatusCode());
        $this->assertSame('application/pdf', $this->api('GET', '/kiosk/transfers/'.$order['number'].'/proof', 1)->getHeaderLine('Content-Type'));

        // Once it's paid, the copy of their bank screen is deleted.
        $this->api('POST', '/kiosk/transfers/'.$order['number'].'/paid', 1);
        $this->assertSame(404, $this->api('GET', '/kiosk/transfers/'.$order['number'].'/proof', 1)->getStatusCode());
    }

    #[Test]
    public function a_seller_paid_by_transfer_marks_their_own_orders_and_no_one_elses(): void
    {
        TestItemUnlock::$prepared = [];
        $this->extend(new UnlockType('test-item', TestItemUnlock::class));
        $this->setting('ernestdefoe-kiosk.sellers', '1');
        $this->prepareDatabase(['group_permission' => [['group_id' => 3, 'permission' => 'kiosk.sell'], ['group_id' => 3, 'permission' => 'kiosk.sellTrusted']]]);

        $this->assertSame(422, $this->api('POST', '/kiosk/selling/accounts/bank', self::NORMAL, ['details' => ' '])->getStatusCode());
        $connected = $this->api('POST', '/kiosk/selling/accounts/bank', self::NORMAL, ['details' => "Seller Test Account\nSELLERBANK 1234", 'instructions' => 'Thanks!']);
        $this->assertSame('connected', collect($this->json($connected)['data']['providers'])->firstWhere('key', 'bank')['status']);
        // Sealed like any secret.
        $this->assertStringNotContainsString('SELLERBANK', (string) $this->db()->table('kiosk_seller_accounts')->value('secrets'));

        $listing = $this->json($this->api('POST', '/kiosk/selling/listings', self::NORMAL, [
            'name' => 'Seller pack', 'unlocks' => [['type' => 'test-item', 'targetId' => 201]],
            'prices' => [['kind' => 'one_off', 'amount' => '8.00', 'currency' => 'USD']],
        ]))['data']['listings'][0];
        $this->assertSame('bank', $listing['providers'][0]['key']);

        $order = $this->order($listing);
        // The buyer is shown the seller's account, not the forum's.
        $mine = $this->json($this->api('GET', '/kiosk/purchases/'.$order['number'], self::BUYER))['data'];
        $this->assertSame("Seller Test Account\nSELLERBANK 1234", $mine['transfer']['details']);

        // On the seller's page, waiting (not a sale yet).
        $page = $this->json($this->api('GET', '/kiosk/selling', self::NORMAL))['data'];
        $this->assertSame($order['number'], $page['awaiting'][0]['number']);
        $this->assertSame([], $page['sales']);

        // The account can't be removed while buyers are paying into it.
        $this->assertSame(422, $this->api('DELETE', '/kiosk/selling/accounts/bank', self::NORMAL)->getStatusCode());

        // Another seller can't mark it; this seller can, once.
        $this->assertSame(404, $this->api('POST', '/kiosk/transfers/'.$order['number'].'/paid', self::STAFF)->getStatusCode());
        $this->assertSame(204, $this->api('POST', '/kiosk/transfers/'.$order['number'].'/paid', self::NORMAL)->getStatusCode());
        $this->assertSame(422, $this->api('POST', '/kiosk/transfers/'.$order['number'].'/paid', self::NORMAL)->getStatusCode());
        $this->assertSame('active', $this->statusOf($order['number']));
        $this->assertTrue($this->db()->table('kiosk_entitlements')->where('user_id', self::BUYER)->where('type', 'test-item')->where('target_id', 201)->exists());
        $this->assertSame(1, $this->db()->table('kiosk_payments')->count());

        // ...and a seller can't touch the forum's own orders.
        $forum = $this->order($this->gold());
        $this->assertSame(404, $this->api('POST', '/kiosk/transfers/'.$forum['number'].'/paid', self::NORMAL)->getStatusCode());
    }

    #[Test]
    public function the_buyer_cannot_mark_a_gift_paid_and_the_recipient_sees_no_bank_details(): void
    {
        $order = $this->order($this->gold(), self::BUYER, ['giftTo' => 'normal']);

        $this->assertSame(404, $this->api('POST', '/kiosk/transfers/'.$order['number'].'/paid', self::BUYER)->getStatusCode());
        $this->assertSame(404, $this->api('POST', '/kiosk/transfers/'.$order['number'].'/paid', self::NORMAL)->getStatusCode());

        // The giver pays, so the giver sees how.
        $this->assertSame(self::DETAILS, $this->json($this->api('GET', '/kiosk/purchases/'.$order['number'], self::BUYER))['data']['transfer']['details']);
        $this->assertNull($this->json($this->api('GET', '/kiosk/purchases/'.$order['number'], self::NORMAL))['data']['transfer']);
        $this->assertSame([], $this->json($this->api('GET', '/kiosk/purchases', self::NORMAL))['data']);

        $this->api('POST', '/kiosk/transfers/'.$order['number'].'/paid', 1);
        $this->assertTrue($this->inGold(self::NORMAL));
    }

    #[Test]
    public function the_order_email_is_given_the_details_reference_and_order_page(): void
    {
        $this->setting('ernestdefoe-kiosk.bank_reference', 'SHOP {number}!!');
        $order = $this->order($this->gold());
        $purchase = \Ernestdefoe\Kiosk\Model\Purchase::query()->where('number', $order['number'])->firstOrFail();
        $mail = (new \Ernestdefoe\Kiosk\Notification\PurchaseBlueprint($purchase, 'awaiting'))->email();
        $params = $mail['params'];

        // Only characters every bank takes.
        $this->assertSame('SHOP '.$order['number'], $params['{reference}']);
        // The account is a block of its own, never handed to the Markdown parser.
        $this->assertSame(['details' => self::DETAILS, 'instructions' => 'Transfers take a day or two.'], $mail['block']);
        $this->assertStringNotContainsString('TEST 0000', implode(' ', $params));
        $this->assertSame('12.50 EUR', $params['{amount}']);
        $this->assertSame($order['url'], $params['{order_url}']);
        $this->assertNotSame('', $params['{deadline}']);

        // Other emails about the order never carry the account.
        $this->assertArrayNotHasKey('block', (new \Ernestdefoe\Kiosk\Notification\PurchaseBlueprint($purchase, 'receipt'))->email());
    }
}
