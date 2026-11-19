<?php

namespace Ernestdefoe\Kiosk\Access;

use Ernestdefoe\Kiosk\Service\Gate;
use Flarum\Discussion\Discussion;
use Flarum\User\Access\AbstractPolicy;
use Flarum\User\User;

/** Replying to a paid discussion takes having bought it. */
class DiscussionPolicy extends AbstractPolicy
{
    public function __construct(private Gate $gate)
    {
    }

    public function reply(User $actor, Discussion $discussion): ?string
    {
        return $this->gate->isDiscussionLocked($actor, $discussion) ? $this->deny() : null;
    }
}
