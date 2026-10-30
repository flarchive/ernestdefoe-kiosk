<?php

namespace Ernestdefoe\Kiosk\Tests\integration\api;

use Carbon\Carbon;
use Ernestdefoe\Kiosk\Tests\integration\KioskTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 🚨 Paid content is checked on every discussion and post in a list: the
 * number of queries must not grow with the number of items.
 */
class QueryCountTest extends KioskTestCase
{
    private function seed(int $count): void
    {
        $now = Carbon::now();
        for ($i = 100; $i < 100 + $count; $i++) {
            // Discussion, then its post, then the discussion's pointers: the
            // foreign keys run both ways (MySQL and PostgreSQL check them).
            $this->db()->table('discussions')->insert(['id' => $i, 'title' => "Paid $i", 'slug' => "paid-$i", 'user_id' => 1, 'created_at' => $now, 'last_posted_at' => $now, 'comment_count' => 1, 'last_post_number' => 1]);
            $this->db()->table('posts')->insert(['id' => $i, 'discussion_id' => $i, 'user_id' => 1, 'type' => 'comment', 'content' => '<t>Secret '.$i.'</t>', 'number' => 1, 'created_at' => $now]);
            $this->db()->table('discussions')->where('id', $i)->update(['first_post_id' => $i, 'last_post_id' => $i]);
            $this->db()->table('discussion_tag')->insert(['discussion_id' => $i, 'tag_id' => self::LOUNGE]);
        }
    }

    private function queries(?int $user, string $path, array $query): int
    {
        $this->api('GET', $path, $user, [], $query); // warm caches, as on a live forum
        $this->db()->flushQueryLog();
        $this->db()->enableQueryLog();
        $response = $this->api('GET', $path, $user, [], $query);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $n = count($this->db()->getQueryLog());
        $this->db()->disableQueryLog();

        return $n;
    }

    #[Test]
    public function lists_cost_the_same_for_one_item_or_twenty(): void
    {
        $this->product([['type' => 'tag', 'targetId' => self::LOUNGE]], [['kind' => 'one_off', 'amount' => '5.00', 'currency' => 'USD']]);
        $this->product([['type' => 'discussion', 'targetId' => 1]], [['kind' => 'one_off', 'amount' => '1.00', 'currency' => 'USD']]);
        $this->product([['type' => 'post', 'targetId' => self::LOUNGE], ['type' => 'tip', 'targetId' => self::LOUNGE]], [['kind' => 'custom', 'amount' => '1.00', 'currency' => 'USD']]);

        $query = fn (int $limit) => ['include' => 'user,firstPost,mostRelevantPost,tags', 'page' => ['limit' => $limit]];

        $this->seed(1);
        $one = ['guest' => $this->queries(null, '/discussions', $query(1)), 'member' => $this->queries(2, '/discussions', $query(1))];

        $this->db()->table('discussion_tag')->where('discussion_id', '>=', 100)->delete();
        $this->db()->table('discussions')->where('id', '>=', 100)->update(['first_post_id' => null, 'last_post_id' => null]);
        $this->db()->table('posts')->where('id', '>=', 100)->delete();
        $this->db()->table('discussions')->where('id', '>=', 100)->delete();
        $this->seed(20);

        $this->assertSame($one['guest'], $this->queries(null, '/discussions', $query(20)), 'guest: queries grew with the list');
        $this->assertSame($one['member'], $this->queries(2, '/discussions', $query(20)), 'member: queries grew with the list');
    }

    #[Test]
    public function a_profile_s_posts_across_paid_discussions_cost_the_same_for_one_or_twenty(): void
    {
        $this->product([['type' => 'tag', 'targetId' => self::LOUNGE]], [['kind' => 'one_off', 'amount' => '5.00', 'currency' => 'USD']]);
        $query = fn (int $limit) => ['filter' => ['author' => 'admin'], 'include' => 'discussion,user', 'page' => ['limit' => $limit]];

        $this->seed(20);
        $one = $this->queries(2, '/posts', $query(1));
        $this->assertSame($one, $this->queries(2, '/posts', $query(20)), 'queries grew with the list');
    }
}
