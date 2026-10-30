<?php

namespace Ernestdefoe\Kiosk\Access;

use Ernestdefoe\Kiosk\Service\Gate;
use Flarum\Tags\Tag;
use Flarum\User\Access\AbstractPolicy;
use Flarum\User\User;

/**
 * Starting a discussion in a paid teaser tag takes having bought the tag; in
 * a tag where posting is paid, a listing credit or a posting pass.
 */
class TagPolicy extends AbstractPolicy
{
    public function __construct(private Gate $gate, private \Ernestdefoe\Kiosk\Service\Credits $credits)
    {
    }

    public function startDiscussion(User $actor, Tag $tag): ?string
    {
        $id = (int) $tag->id;

        return in_array($id, $this->gate->lockedTagIds($actor), true) || in_array($id, $this->credits->postLockedTags($actor), true)
            ? $this->deny()
            : null;
    }
}
