<?php

namespace Ernestdefoe\Kiosk\Listener;

use Ernestdefoe\Kiosk\Service\Credits;
use Flarum\Tags\Event\DiscussionWasTagged;
use Illuminate\Database\ConnectionInterface;

/**
 * 🚨 Moving a discussion INTO a tag where posting is paid is posting there:
 * it spends a credit, or a free discussion retagged would be a free listing.
 * The tag policy already refused anyone with no credit or pass.
 */
class SpendCreditOnRetag
{
    public function __construct(private Credits $credits, private ConnectionInterface $db)
    {
    }

    public function handle(DiscussionWasTagged $event): void
    {
        $paid = $this->credits->tags()['post'];
        if (! $paid) {
            return;
        }

        $old = array_map(fn ($tag) => (int) $tag->id, $event->oldTags);
        $now = $this->db->table('discussion_tag')->where('discussion_id', $event->discussion->id)->pluck('tag_id')->map(fn ($v) => (int) $v)->all();
        $added = array_values(array_intersect(array_diff($now, $old), $paid));

        if ($added) {
            $this->credits->spend($event->actor, 'post', $added);
        }
    }
}
