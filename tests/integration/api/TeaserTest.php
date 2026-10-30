<?php

namespace Ernestdefoe\Kiosk\Tests\integration\api;

use Ernestdefoe\Kiosk\Tests\integration\KioskTestCase;
use PHPUnit\Framework\Attributes\Test;

/** Paid content shown from the outside: titles and opening words, never the rest. */
class TeaserTest extends KioskTestCase
{
    /** @return array{0: int, 1: string} status and the raw body of everything this person can read of discussion 1 */
    private function read(?int $user): array
    {
        $show = $this->api('GET', '/discussions/1', $user);
        $posts = $this->api('GET', '/posts', $user, [], ['filter' => ['discussion' => 1]]);

        return [$show->getStatusCode(), (string) $show->getBody().(string) $posts->getBody()];
    }

    private function sellLounge(): array
    {
        return $this->product([['type' => 'tag', 'targetId' => self::LOUNGE]], [['kind' => 'one_off', 'amount' => '5.00', 'currency' => 'USD']]);
    }

    #[Test]
    public function a_teaser_tag_shows_titles_and_opening_words_only(): void
    {
        $product = $this->sellLounge();
        $this->assertFalse((bool) $this->db()->table('tags')->where('id', self::LOUNGE)->value('is_restricted'), 'A teaser tag stays visible');

        foreach ([[2, 'member'], [null, 'guest']] as [$user, $who]) {
            [$status, $body] = $this->read($user);
            $this->assertSame(200, $status, $who.': '.substr($body, 0, 400));
            $this->assertStringContainsString('Members only', $body, "$who sees the title");
            $this->assertStringContainsString('Behind the rope', $body, "$who sees the opening words");
            $this->assertStringNotContainsString('FINALE-SECRET', $body, "$who never gets the rest of the first post");
            $this->assertStringNotContainsString('REPLY-SECRET', $body, "$who never gets a reply");
            $this->assertStringContainsString('"kioskLocked":true', $body);
        }

        // Replying is for buyers.
        $reply = $this->api('POST', '/posts', 2, ['type' => 'posts', 'attributes' => ['content' => 'Hi'], 'relationships' => ['discussion' => ['data' => ['type' => 'discussions', 'id' => '1']]]]);
        $this->assertSame(403, $reply->getStatusCode());

        // A buyer reads all of it.
        $this->api('POST', '/kiosk/admin/grants', 1, ['user' => 'buyer', 'productId' => $product['id'], 'days' => 0]);
        [, $body] = $this->read(self::BUYER);
        $this->assertStringContainsString('FINALE-SECRET', $body);
        $this->assertStringContainsString('REPLY-SECRET', $body);
    }

    #[Test]
    public function staff_with_the_permission_read_without_buying(): void
    {
        $this->sellLounge();
        [, $body] = $this->read(self::STAFF);

        $this->assertStringContainsString('FINALE-SECRET', $body);
        $this->assertStringContainsString('REPLY-SECRET', $body);
    }

    #[Test]
    public function one_discussion_can_be_sold_by_itself(): void
    {
        $product = $this->product([['type' => 'discussion', 'targetId' => 1]], [['kind' => 'one_off', 'amount' => '2.00', 'currency' => 'USD']]);
        $this->assertSame('Members only', $product['unlocks'][0]['label']);

        [, $body] = $this->read(2);
        $this->assertStringNotContainsString('REPLY-SECRET', $body);
        $this->assertStringNotContainsString('FINALE-SECRET', $body);

        $this->api('POST', '/kiosk/admin/grants', 1, ['user' => 'buyer', 'productId' => $product['id'], 'days' => 0]);
        [, $body] = $this->read(self::BUYER);
        $this->assertStringContainsString('REPLY-SECRET', $body);
    }

    #[Test]
    public function rendering_with_no_reader_fails_closed(): void
    {
        $this->sellLounge();
        $this->boot();
        $post = \Flarum\Post\CommentPost::query()->find(1);

        // An email or a sitemap renders with no request: treated as a guest.
        $this->assertStringNotContainsString('FINALE-SECRET', $post->formatContent());
    }
}
