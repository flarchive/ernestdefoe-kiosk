<?php

namespace Ernestdefoe\Kiosk\Listener;

use Ernestdefoe\Kiosk\Service\Gate;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\Post\Post;
use Flarum\User\User;

/**
 * 🚨 The teaser only applies when a post is rendered on the forum. Emails
 * (fof/follow-tags' "new discussion", mentions) put the post's raw text in
 * their plain-text part, so a member who hasn't bought a paid discussion
 * would get all of it by email. Nobody is notified about a post in a
 * discussion they can't read: they still see it listed, with its teaser.
 */
class WithholdPaidPosts
{
    public function __construct(private Gate $gate)
    {
    }

    /**
     * @param list<User> $recipients
     * @return list<User>
     */
    public function __invoke(BlueprintInterface $blueprint, array $recipients): array
    {
        $posts = array_filter([$blueprint->getSubject(), ...array_values(get_object_vars($blueprint))], fn ($v) => $v instanceof Post && $v->discussion_id);

        if (! $posts || ! $recipients) {
            return $recipients;
        }

        return array_values(array_filter($recipients, function (User $user) use ($posts) {
            foreach ($posts as $post) {
                if ($this->gate->isLocked($user, (int) $post->discussion_id, $post->user_id ? (int) $post->user_id : null)) {
                    return false;
                }
            }

            return true;
        }));
    }
}
