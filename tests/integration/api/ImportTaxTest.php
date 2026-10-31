<?php

namespace Ernestdefoe\Kiosk\Tests\integration\api;

use Carbon\Carbon;
use Ernestdefoe\Kiosk\Import\FloxumImporter;
use Ernestdefoe\Kiosk\Tests\integration\KioskTestCase;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\Attributes\Test;

/** Moving from floxum/payments, and Stripe Tax. */
class ImportTaxTest extends KioskTestCase
{
    private function floxum(): void
    {
        // 🚨 It adds floxum's columns; on MySQL and MariaDB that commits the
        // test's transaction and leaks rows into every later test.
        if (! in_array($this->db()->getDriverName(), ['sqlite', 'pgsql'], true)) {
            $this->markTestSkipped('Schema changes inside a test end its transaction on '.$this->db()->getDriverName().'.');
        }

        $schema = $this->db()->getSchemaBuilder();
        $schema->table('groups', fn (Blueprint $t) => $t->string('blomstra_pta_stripe_product_id')->nullable());
        $schema->table('tags', function (Blueprint $t) {
            $t->string('blomstra_ptv_stripe_product_id')->nullable();
            $t->string('blomstra_bounty_stripe_product_id')->nullable();
        });
        $schema->table('discussion_user', fn (Blueprint $t) => $t->string('blomstra_ptv_stripe_payment_id')->nullable());
        $schema->create('blomstra_payments_bounties', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedInteger('discussion_id');
            $t->unsignedInteger('user_id');
            $t->string('stripe_id');
            $t->integer('amount');
            $t->string('currency');
            $t->timestamp('created_at');
        });

        $this->db()->table('groups')->where('id', self::GOLD)->update(['blomstra_pta_stripe_product_id' => 'prod_gold']);
        $this->db()->table('tags')->where('id', self::LOUNGE)->update(['blomstra_ptv_stripe_product_id' => 'prod_view', 'blomstra_bounty_stripe_product_id' => 'prod_bounty']);
        $this->db()->table('discussion_user')->insert(['user_id' => 2, 'discussion_id' => 1, 'blomstra_ptv_stripe_payment_id' => 'pi_view']);
        $this->db()->table('blomstra_payments_bounties')->insert(['discussion_id' => 1, 'user_id' => 2, 'stripe_id' => 'pi_bounty', 'amount' => 700, 'currency' => 'usd', 'created_at' => Carbon::now()]);
    }

    private function import(): array
    {
        $this->boot();
        $importer = $this->app()->getContainer()->make(FloxumImporter::class);
        $prices = fn ($product) => match ($product) {
            'prod_gold' => [['id' => 'price_gold_m', 'type' => 'recurring', 'unit_amount' => 500, 'currency' => 'usd', 'recurring' => ['interval' => 'month', 'interval_count' => 1]]],
            'prod_view' => [['id' => 'price_view', 'type' => 'one_time', 'unit_amount' => 300, 'currency' => 'usd']],
            'prod_bounty' => [['id' => 'price_bounty', 'type' => 'one_time', 'custom_unit_amount' => ['enabled' => true, 'minimum' => 100], 'currency' => 'usd']],
            default => [],
        };
        // The fake answers lists by query: prices by product, subscriptions by price.
        $this->stripe->lister = function (string $path, array $params) use ($prices) {
            if ($path === '/v1/prices') {
                return $prices($params['product'] ?? '');
            }
            if ($path === '/v1/subscriptions' && ($params['price'] ?? '') === 'price_gold_m') {
                return [
                    ['id' => 'sub_old', 'status' => 'active', 'customer' => 'cus_1', 'metadata' => ['flarum-user-id' => (string) self::BUYER], 'current_period_start' => time(), 'current_period_end' => strtotime('+20 days')],
                    ['id' => 'sub_gone', 'status' => 'canceled', 'metadata' => ['flarum-user-id' => '2']],
                ];
            }

            return null;
        };

        return $importer->run();
    }

    #[Test]
    public function floxum_products_subscriptions_readers_and_bounties_come_across_once(): void
    {
        $this->floxum();
        $counts = $this->import();

        $this->assertSame(3, $counts['products']);
        $this->assertSame(1, $counts['subscriptions']);
        $this->assertSame(1, $counts['readers']);
        $this->assertSame(1, $counts['tips']);

        // The subscription is Kiosk's now, on the same Stripe price, with no receipt sent.
        $purchase = $this->db()->table('kiosk_purchases')->where('provider_ref', 'sub_old')->first();
        $this->assertSame(self::BUYER, (int) $purchase->user_id);
        $this->assertSame('active', $purchase->status);
        $this->assertSame('price_gold_m', $this->db()->table('kiosk_price_refs')->where('price_id', $purchase->price_id)->value('external_id'));
        $this->assertTrue($this->db()->table('group_user')->where('user_id', self::BUYER)->where('group_id', self::GOLD)->exists());
        $this->assertSame(0, $this->db()->table('notifications')->count());
        $this->assertSame($purchase->number, collect($this->stripe->calls)->firstWhere('path', '/v1/subscriptions/sub_old')['params']['metadata']['kiosk_number']);

        // The Lounge is a teaser now, but the reader who paid for discussion 1 still reads it.
        $this->assertStringContainsString('FINALE-SECRET', (string) $this->api('GET', '/discussions/1', 2, [], ['include' => 'firstPost'])->getBody());
        $this->assertStringNotContainsString('FINALE-SECRET', (string) $this->api('GET', '/discussions/1', null, [], ['include' => 'firstPost'])->getBody());

        // The bounty is a tip on the same discussion.
        $tip = $this->db()->table('kiosk_purchases')->where('context_type', 'discussion')->first();
        $this->assertSame(700, (int) $tip->amount_cents);
        $this->assertSame(1, (int) $tip->context_id);

        // A second run imports nothing new.
        $again = $this->import();
        $this->assertSame(['products' => 0, 'subscriptions' => 0, 'readers' => 0, 'tips' => 0, 'skipped' => 3], $again);
    }

    #[Test]
    public function stripe_tax_asks_stripe_to_add_tax_and_judges_the_amount_before_it(): void
    {
        $this->setting('ernestdefoe-kiosk.stripe_tax', '1');
        $product = $this->product([['type' => 'group', 'targetId' => self::GOLD]], [['kind' => 'one_off', 'amount' => '5.00', 'currency' => 'USD']]);

        $number = $this->json($this->api('POST', '/kiosk/checkout', self::BUYER, ['priceId' => $product['prices'][0]['id'], 'provider' => 'stripe']))['data']['number'];
        $session = collect($this->stripe->calls)->firstWhere('path', '/v1/checkout/sessions');
        $this->assertSame('true', $session['params']['automatic_tax']['enabled']);

        // $5 plus 40¢ tax: the $5 is what Kiosk asked for, and access opens.
        $this->stripe->amountTotal = 540;
        $this->stripe->subtotal = 500;
        $this->api('POST', '/kiosk/purchases/'.$number.'/confirm', self::BUYER);
        $this->assertSame('active', $this->db()->table('kiosk_purchases')->value('status'));
        $this->assertSame(540, (int) $this->db()->table('kiosk_payments')->value('amount_cents'));
    }
}
