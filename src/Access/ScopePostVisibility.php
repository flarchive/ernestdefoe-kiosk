<?php

namespace Ernestdefoe\Kiosk\Access;

use Ernestdefoe\Kiosk\Service\Gate;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Replies in a paid discussion are left out of every post query — the
 * discussion page, /api/posts, search, feeds — for anyone who hasn't bought
 * it. The first post stays (rendered as a teaser, and left out of search),
 * and so do their own posts.
 */
class ScopePostVisibility
{
    public function __construct(private Gate $gate)
    {
    }

    /** @param Builder<\Flarum\Post\Post> $query */
    public function __invoke(User $actor, Builder $query): void
    {
        $tags = $this->gate->lockedTagIds($actor);
        $discussions = $this->gate->lockedDiscussionIds($actor);

        if (! $tags && ! $discussions) {
            return;
        }

        $searching = $this->gate->searching;

        $query->where(function (Builder $q) use ($actor, $tags, $discussions, $searching) {
            // The first post shows as a teaser, but isn't searched (Gate::$searching).
            if (! $searching) {
                $q->orWhere('posts.number', 1);
            }
            if (! $actor->isGuest()) {
                $q->orWhere('posts.user_id', $actor->id);
            }
            $q->orWhere(function (Builder $open) use ($tags, $discussions) {
                if ($discussions) {
                    $open->whereNotIn('posts.discussion_id', $discussions);
                }
                if ($tags) {
                    $open->whereNotIn('posts.discussion_id', fn ($sub) => $sub->select('discussion_id')->from('discussion_tag')->whereIn('tag_id', $tags));
                }
            });
        });
    }
}
