<?php

namespace Ernestdefoe\Kiosk\Service;

use Flarum\Discussion\Discussion;
use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\ConnectionInterface;

/**
 * Which discussions somebody may only see the outside of: those in a paid
 * tag shown as a teaser, and discussions sold one by one ("pay to view"),
 * unless they hold them.
 *
 * 🚨 Asked while posts render and while post queries are built, so it costs
 * almost nothing: the lists of teaser tags and sold discussions are cached
 * until a product changes, what the member holds comes from Access (cached
 * per member), and a discussion's tags are looked up once per request.
 */
class Gate
{
    private const KEY = 'kiosk.gate';

    /** @var array{tags: list<int>, discussions: list<int>}|null */
    private ?array $sold = null;

    /** @var array<int, list<int>> discussion id => tag ids */
    private array $tagsOf = [];

    public function __construct(private ConnectionInterface $db, private Cache $cache, private Access $access)
    {
    }

    /** Admins, staff with "Read paid content without buying". */
    public function bypasses(User $actor): bool
    {
        return $actor->isAdmin() || (! $actor->isGuest() && $actor->hasPermission('kiosk.readPaid'));
    }

    /** @return array{tags: list<int>, discussions: list<int>} */
    public function sold(): array
    {
        return $this->sold ??= (array) $this->cache->remember(self::KEY, 3600, fn () => [
            'tags' => $this->db->table('kiosk_tag_groups')
                ->where('teaser', true)
                ->whereIn('tag_id', fn ($q) => $q->select('kiosk_product_unlocks.target_id')->from('kiosk_product_unlocks')
                    ->join('kiosk_products', 'kiosk_products.id', '=', 'kiosk_product_unlocks.product_id')
                    ->where('kiosk_product_unlocks.type', 'tag')->where('kiosk_products.is_active', true))
                ->pluck('tag_id')->map(fn ($v) => (int) $v)->values()->all(),
            'discussions' => $this->db->table('kiosk_product_unlocks')
                ->join('kiosk_products', 'kiosk_products.id', '=', 'kiosk_product_unlocks.product_id')
                ->where('kiosk_product_unlocks.type', 'discussion')->where('kiosk_products.is_active', true)
                ->pluck('kiosk_product_unlocks.target_id')->map(fn ($v) => (int) $v)->unique()->values()->all(),
        ]);
    }

    public function forget(): void
    {
        $this->sold = null;
        $this->cache->forget(self::KEY);
    }

    /** Drop this process's memo only (a queue worker, between jobs). */
    public function flush(): void
    {
        $this->sold = null;
        $this->tagsOf = [];
        $this->searching = false;
    }

    /**
     * 🚨 A search reads the whole first post, not its teaser: searching
     * for a word would tell a non-buyer whether the hidden part has it. For
     * the rest of a search request, locked first posts aren't searchable.
     */
    public bool $searching = false;

    /** Teaser tags this person hasn't bought. @return list<int> */
    public function lockedTagIds(User $actor): array
    {
        return $this->bypasses($actor) ? [] : array_values(array_diff($this->sold()['tags'], $this->access->ids($actor, 'tag')));
    }

    /** Discussions sold one by one that this person hasn't bought. @return list<int> */
    public function lockedDiscussionIds(User $actor): array
    {
        return $this->bypasses($actor) ? [] : array_values(array_diff($this->sold()['discussions'], $this->access->ids($actor, 'discussion')));
    }

    /**
     * The tags of a whole page of discussions in one query, before it's
     * serialized: every per-row check after that is free. Nothing when
     * nothing is for sale.
     *
     * @param iterable<\Flarum\Database\AbstractModel> $models discussions, or posts
     * @param string $key the attribute holding the discussion id
     */
    public function prime(iterable $models, string $key): void
    {
        if (! $this->sold()['tags']) {
            return;
        }

        $ids = [];
        foreach ($models as $model) {
            if ($id = (int) $model->getAttribute($key)) {
                $ids[$id] = $id;
            }
        }

        $missing = array_values(array_diff($ids, array_keys($this->tagsOf)));
        if (! $missing) {
            return;
        }

        foreach ($missing as $id) {
            $this->tagsOf[$id] = [];
        }
        foreach ($this->db->table('discussion_tag')->whereIn('discussion_id', $missing)->get(['discussion_id', 'tag_id']) as $row) {
            $this->tagsOf[(int) $row->discussion_id][] = (int) $row->tag_id;
        }
    }

    /** isLocked for a discussion model, using its tags when they're already loaded (lists). */
    public function isDiscussionLocked(User $actor, Discussion $discussion): bool
    {
        if ($discussion->relationLoaded('tags')) {
            $this->tagsOf[(int) $discussion->id] ??= $discussion->getRelation('tags')->pluck('id')->map(fn ($v) => (int) $v)->all();
        }

        return $this->isLocked($actor, (int) $discussion->id, $discussion->user_id ? (int) $discussion->user_id : null);
    }

    /** Whether this person sees only the outside of this discussion. */
    public function isLocked(User $actor, int $discussionId, ?int $authorId = null): bool
    {
        // Nothing is for sale: the common case costs nothing at all.
        if (! $this->sold()['tags'] && ! $this->sold()['discussions']) {
            return false;
        }
        if ($this->bypasses($actor)) {
            return false;
        }
        // People always read what they wrote.
        if ($authorId !== null && ! $actor->isGuest() && $authorId === (int) $actor->id) {
            return false;
        }
        if (in_array($discussionId, $this->lockedDiscussionIds($actor), true)) {
            return true;
        }

        $locked = $this->lockedTagIds($actor);

        // One discussion bought on its own opens even inside a paid tag.
        if ($locked !== [] && in_array($discussionId, $this->access->ids($actor, 'discussion'), true)) {
            return false;
        }

        return $locked !== [] && array_intersect($this->tagsOf($discussionId), $locked) !== [];
    }

    /** @return list<int> */
    public function tagsOf(int $discussionId): array
    {
        return $this->tagsOf[$discussionId] ??= $this->db->table('discussion_tag')->where('discussion_id', $discussionId)
            ->pluck('tag_id')->map(fn ($v) => (int) $v)->all();
    }

}
