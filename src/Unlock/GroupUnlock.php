<?php

namespace Ernestdefoe\Kiosk\Unlock;

use Carbon\Carbon;
use Flarum\Group\Group;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;

/**
 * Membership of a group: a supporter tier, a premium membership. The group's
 * permissions are whatever the admin gives it on the Permissions page, and
 * its badge shows like any other group's.
 */
class GroupUnlock implements UnlockType
{
    /** Groups that must never be for sale. */
    private const NEVER = [Group::ADMINISTRATOR_ID, Group::GUEST_ID, Group::MEMBER_ID];

    public function __construct(private ConnectionInterface $db)
    {
    }

    public function label(): string
    {
        return 'ernestdefoe-kiosk.lib.unlock_types.group';
    }

    public function icon(): string
    {
        return 'fas fa-user-group';
    }

    public function search(User $actor, string $query, int $limit): array
    {
        return Group::query()
            ->whereNotIn('id', self::NEVER)
            // A paid tag's hidden group is sold as the tag, not by itself.
            ->whereNotIn('id', $this->db->table('kiosk_tag_groups')->select('group_id'))
            ->when($query !== '', fn ($q) => $q->where('name_singular', 'like', '%'.addcslashes($query, '%_\\').'%'))
            ->orderBy('name_singular')
            ->limit($limit)
            ->get(['id', 'name_singular'])
            ->map(fn (Group $g) => ['id' => (int) $g->id, 'label' => (string) $g->name_singular])
            ->all();
    }

    public function describe(array $ids): array
    {
        return Group::query()->whereIn('id', $ids)->get(['id', 'name_plural'])
            ->mapWithKeys(fn (Group $g) => [(int) $g->id => ['label' => (string) $g->name_plural, 'url' => null]])
            ->all();
    }

    public function grant(User $user, int $targetId): void
    {
        if (in_array($targetId, self::NEVER, true) || ! Group::query()->whereKey($targetId)->exists()) {
            return;
        }

        $already = $this->db->table('group_user')->where('user_id', $user->id)->where('group_id', $targetId)->exists();

        // Already a member through somebody else: leave it theirs, record nothing.
        if ($already) {
            return;
        }

        $this->db->transaction(function () use ($user, $targetId) {
            $this->db->table('group_user')->insert(['user_id' => $user->id, 'group_id' => $targetId]);
            $this->db->table('kiosk_group_grants')->insert(['user_id' => $user->id, 'group_id' => $targetId, 'granted_at' => Carbon::now()]);
        });

        $user->unsetRelation('groups');
    }

    public function revoke(User $user, int $targetId): void
    {
        $ours = $this->db->table('kiosk_group_grants')->where('user_id', $user->id)->where('group_id', $targetId)->delete();

        if ($ours) {
            $this->db->table('group_user')->where('user_id', $user->id)->where('group_id', $targetId)->delete();
            $user->unsetRelation('groups');
        }
    }

    public function prepare(int $targetId): void
    {
    }
}
