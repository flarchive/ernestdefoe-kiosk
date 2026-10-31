<?php

namespace Ernestdefoe\Kiosk\Unlock;

use Flarum\User\User;

/**
 * An unlock type members may sell, for the things they own: their Depot
 * uploads, their courses, their albums. Types without it (groups, tags,
 * discussions, posting credits…) can only be sold by the forum, so a member
 * can never sell access to the forum itself.
 */
interface SellableByMembers
{
    public function owns(User $seller, int $targetId): bool;

    /**
     * The seller's own items matching $query, for their listing form.
     *
     * @return list<array{id: int, label: string}>
     */
    public function searchOwn(User $seller, string $query, int $limit): array;
}
