<?php

namespace Ernestdefoe\Kiosk\Unlock;

use Flarum\User\User;

/**
 * A kind of thing Kiosk can sell. Kiosk ships "group" and "tag"; any
 * extension registers its own with Extend\UnlockType and gates its items by
 * asking Access whether the actor holds them.
 */
interface UnlockType
{
    /** The translation key of its name in the admin ("Group", "Course"). */
    public function label(): string;

    public function icon(): string;

    /**
     * Items the admin may attach to a product, matching $query.
     *
     * @return list<array{id: int, label: string}>
     */
    public function search(User $actor, string $query, int $limit): array;

    /**
     * Names and links for these ids, for product cards, receipts and the admin.
     * A missing id (deleted since) is simply left out.
     *
     * @param list<int> $ids
     * @return array<int, array{label: string, url: ?string}>
     */
    public function describe(array $ids): array;

    /** Called when access starts. Must be safe to call again for the same user and item. */
    public function grant(User $user, int $targetId): void;

    /** Called when the last live entitlement for this user and item ends. */
    public function revoke(User $user, int $targetId): void;

    /** Called when a product starts selling this item, to make it sellable (e.g. restrict a tag). */
    public function prepare(int $targetId): void;
}
