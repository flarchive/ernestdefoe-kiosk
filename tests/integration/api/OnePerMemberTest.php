<?php

namespace Ernestdefoe\Kiosk\Tests\integration\api;

use Ernestdefoe\Kiosk\Tests\integration\KioskTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * "One per member": a member can't buy again what they already have, or what
 * a bank transfer of theirs is paying for. Consumables can always be bought
 * again. Reported by a forum selling courses (bought, then bought again).
 */
class OnePerMemberTest extends KioskTestCase
{
    private const NORMAL = 2;

    protected function setUp(): void
    {
        parent::setUp();
        // Fake test details: nothing here is a real account.
        $this->setting('ernestdefoe-kiosk.bank_enabled', '1');
        $this->setting('ernestdefoe-kiosk.bank_details', "Example Forum Ltd\nTest Bank\nIBAN XX00 TEST 0000 0000 0000");
    }

    /** @param array<string, mixed> $extra */
    private function access(array $extra = []): array
    {
        return $this->product([['type' => 'group', 'targetId' => self::GOLD]], [['kind' => 'one_off', 'amount' => '5.00', 'currency' => 'USD']], $extra);
    }

    private function checkout(array $product, string $provider = 'paypal', int $as = self::BUYER, array $options = []): \Psr\Http\Message\ResponseInterface
    {
        return $this->api('POST', '/kiosk/checkout', $as, ['priceId' => $product['prices'][0]['id'], 'provider' => $provider] + $options);
    }

    #[Test]
    public function access_is_one_per_member_by_default_and_consumables_are_not(): void
    {
        $this->assertTrue($this->access()['onePerMember']);
        $this->assertFalse($this->product([['type' => 'post', 'targetId' => self::LOUNGE]], [['kind' => 'one_off', 'amount' => '3.00', 'currency' => 'USD']])['onePerMember']);
        $this->assertFalse($this->product([['type' => 'group', 'targetId' => self::GOLD]], [['kind' => 'custom', 'amount' => '1.00', 'currency' => 'USD']])['onePerMember']);
        $this->assertFalse($this->access(['onePerMember' => false])['onePerMember'], 'The admin decides');
    }

    #[Test]
    public function a_member_who_bought_it_cannot_buy_it_again(): void
    {
        $product = $this->access();
        $number = $this->json($this->checkout($product))['data']['number'];
        // Leaving the payment page unfinished doesn't lock them out.
        $this->assertSame(200, $this->checkout($product)->getStatusCode());

        $this->api('POST', '/kiosk/purchases/'.$number.'/confirm', self::BUYER);

        $again = $this->checkout($product);
        $this->assertSame(422, $again->getStatusCode());
        $this->assertStringContainsString('errors.already_owned', (string) $again->getBody());
        $this->assertContains($product['id'], $this->json($this->api('GET', '/kiosk/store', self::BUYER))['meta']['owned']);

        // Nor can someone buy it for them; someone else still can buy it.
        $gift = $this->checkout($product, 'paypal', self::NORMAL, ['giftTo' => 'buyer']);
        $this->assertSame(422, $gift->getStatusCode());
        $this->assertStringContainsString('errors.gift_already_owned', (string) $gift->getBody());
        $this->assertSame(200, $this->checkout($product, 'paypal', self::NORMAL)->getStatusCode());
    }

    #[Test]
    public function a_bank_transfer_on_the_way_counts_as_having_it(): void
    {
        $product = $this->access();
        $number = $this->json($this->checkout($product, 'bank'))['data']['number'];

        $again = $this->checkout($product, 'paypal');
        $this->assertSame(422, $again->getStatusCode());
        $this->assertStringContainsString('errors.payment_pending', (string) $again->getBody());
        $this->assertSame([(string) $product['id'] => $number], $this->json($this->api('GET', '/kiosk/store', self::BUYER))['meta']['pending']);

        // Called off: it can be bought again.
        $this->api('POST', '/kiosk/transfers/'.$number.'/cancel', self::BUYER);
        $this->assertSame(200, $this->checkout($product)->getStatusCode());
    }

    #[Test]
    public function consumables_and_products_the_admin_opened_up_can_be_bought_again(): void
    {
        // 5.00: what the fake PayPal captures.
        $credits = $this->product([['type' => 'post', 'targetId' => self::LOUNGE]], [['kind' => 'one_off', 'amount' => '5.00', 'currency' => 'USD']]);
        for ($i = 0; $i < 2; $i++) {
            $number = $this->json($this->checkout($credits))['data']['number'];
            $this->api('POST', '/kiosk/purchases/'.$number.'/confirm', self::BUYER);
        }
        $this->assertSame(2, $this->db()->table('kiosk_purchases')->where('product_id', $credits['id'])->where('status', 'active')->count());

        $open = $this->access(['onePerMember' => false]);
        $number = $this->json($this->checkout($open))['data']['number'];
        $this->api('POST', '/kiosk/purchases/'.$number.'/confirm', self::BUYER);
        $this->assertSame(200, $this->checkout($open)->getStatusCode());
    }

    #[Test]
    public function two_checkouts_at_the_same_moment_make_one_purchase(): void
    {
        $product = $this->access();
        $this->api('GET', '/kiosk/store', self::BUYER);

        // The first click's checkout is still running: the second waits for nothing and adds nothing.
        $lock = $this->app()->getContainer()->make(\Illuminate\Contracts\Cache\Repository::class)->getStore()->lock('kiosk.lock.checkout.'.self::BUYER, 30);
        $this->assertTrue($lock->get());

        $second = $this->checkout($product, 'bank');
        $this->assertSame(422, $second->getStatusCode());
        $this->assertStringContainsString('errors.busy', (string) $second->getBody());
        $this->assertSame(0, $this->db()->table('kiosk_purchases')->count());

        $lock->release();
        $this->assertSame(200, $this->checkout($product, 'bank')->getStatusCode());
        $this->assertSame(422, $this->checkout($product, 'bank')->getStatusCode(), 'and once it is placed, a third is refused');
        $this->assertSame(1, $this->db()->table('kiosk_purchases')->count());
    }
}
