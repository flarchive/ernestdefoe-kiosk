<?php

namespace Ernestdefoe\Kiosk\Service;

use Carbon\Carbon;
use Ernestdefoe\Kiosk\Model\Entitlement;
use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\ConnectionInterface;

/**
 * What a member holds right now — the one question every gate asks.
 *
 * 🚨 It runs on every request a member makes (Flarum asks for permission
 * groups on every page), so it must cost nothing in the common case:
 * - guests never touch it;
 * - the answer is cached per member until the next moment it can change on
 *   its own (the earliest ends_at among what they hold), capped at an hour,
 *   and forgotten whenever Kiosk writes something for them;
 * - within a request it is computed once per member.
 * A member who has never bought anything costs one cache read per request.
 */
class Access
{
    private const TTL = 3600;

    /** @var array<int, array{held: list<array{0: string, 1: int}>, granted: list<int>}> */
    private array $memo = [];

    /** @var array<int, int>|null tag id => hidden group id */
    private ?array $tagGroups = null;

    public function __construct(private ConnectionInterface $db, private Cache $cache)
    {
    }

    public function has(User $user, string $type, int $targetId): bool
    {
        if ($user->isGuest()) {
            return false;
        }

        foreach ($this->snapshot($user)['held'] as [$t, $id]) {
            if ($t === $type && $id === $targetId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Ids of every item of this type the member holds — for a visibility
     * scope's whereIn, so a list of 20 albums costs no extra query.
     *
     * @return list<int>
     */
    public function ids(User $user, string $type): array
    {
        if ($user->isGuest()) {
            return [];
        }

        $ids = [];
        foreach ($this->snapshot($user)['held'] as [$t, $id]) {
            if ($t === $type) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Extend\User::permissionGroups: adjust the groups a member's permissions
     * come from.
     *
     * - A held tag adds that tag's hidden group, which is what lets them in.
     * - A held group is added too, so access starts at the instant of payment
     *   even before the membership row is written.
     * - A group Kiosk added whose entitlement has ended is taken out at once,
     *   so a lapsed member loses access on time even if the scheduler that
     *   removes the membership row is late or not running at all.
     *
     * @param list<int> $groupIds
     * @return list<int>
     */
    public function permissionGroups(User $user, array $groupIds): array
    {
        // An unconfirmed account is no more than a guest to Flarum, and so to Kiosk.
        if ($user->isGuest() || ! $user->is_email_confirmed) {
            return $groupIds;
        }

        $snap = $this->snapshot($user);
        $live = [];
        $tagGroups = null;

        foreach ($snap['held'] as [$type, $id]) {
            if ($type === 'group') {
                $live[] = $id;
            } elseif ($type === 'tag') {
                $tagGroups ??= $this->tagGroups();
                if (isset($tagGroups[$id])) {
                    $live[] = $tagGroups[$id];
                }
            }
        }

        $stale = array_diff($snap['granted'], $live);

        return array_values(array_unique([...array_diff($groupIds, $stale), ...$live]));
    }

    /** Drop what is remembered for this member: call after any write that concerns them. */
    public function forget(int $userId): void
    {
        unset($this->memo[$userId]);
        $this->cache->forget($this->key($userId));
    }

    /** Drop this process's memo only (a queue worker, between jobs). */
    public function flush(): void
    {
        $this->memo = [];
        $this->tagGroups = null;
    }

    public function forgetTagGroups(): void
    {
        $this->tagGroups = null;
        $this->cache->forget('kiosk.tag_groups');
    }

    /** @return array<int, int> */
    public function tagGroups(): array
    {
        return $this->tagGroups ??= (array) $this->cache->remember(
            'kiosk.tag_groups',
            self::TTL,
            fn () => $this->db->table('kiosk_tag_groups')->pluck('group_id', 'tag_id')->map(fn ($v) => (int) $v)->all()
        );
    }

    /** @return array{held: list<array{0: string, 1: int}>, granted: list<int>} */
    private function snapshot(User $user): array
    {
        if (isset($this->memo[$user->id])) {
            return $this->memo[$user->id];
        }

        $cached = $this->cache->get($this->key($user->id));

        if (is_array($cached) && isset($cached['held'], $cached['granted'])) {
            return $this->memo[$user->id] = $cached;
        }

        $now = Carbon::now();

        $rows = Entitlement::query()->live($now)->where('user_id', $user->id)
            ->toBase()->get(['type', 'target_id', 'ends_at']);

        $granted = $this->db->table('kiosk_group_grants')->where('user_id', $user->id)->pluck('group_id')->map(fn ($v) => (int) $v)->all();

        $held = [];
        $ttl = self::TTL;

        foreach ($rows as $row) {
            $held[] = [(string) $row->type, (int) $row->target_id];

            if ($row->ends_at !== null) {
                // Expire the cache the second this entitlement does.
                $ttl = max(1, min($ttl, Carbon::parse($row->ends_at)->getTimestamp() - $now->getTimestamp()));
            }
        }

        $snap = ['held' => $held, 'granted' => $granted];
        $this->cache->put($this->key($user->id), $snap, $ttl);

        return $this->memo[$user->id] = $snap;
    }

    private function key(int $userId): string
    {
        return 'kiosk.access.'.$userId;
    }
}
