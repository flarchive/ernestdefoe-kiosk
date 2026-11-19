<?php

namespace Ernestdefoe\Kiosk\Tests\integration\Fixtures;

use Ernestdefoe\Kiosk\Unlock\SellableByMembers;
use Ernestdefoe\Kiosk\Unlock\UnlockType;
use Flarum\User\User;

/** A thing members own: item N belongs to the member whose id is N / 100. */
class TestItemUnlock implements UnlockType, SellableByMembers
{
    public static array $prepared = [];

    public function label(): string
    {
        return 'test.item';
    }

    public function icon(): string
    {
        return 'fas fa-box';
    }

    public function search(User $actor, string $query, int $limit): array
    {
        return [];
    }

    public function describe(array $ids): array
    {
        return array_combine($ids, array_map(fn ($id) => ['label' => 'Item '.$id, 'url' => null], $ids));
    }

    public function grant(User $user, int $targetId): void
    {
    }

    public function revoke(User $user, int $targetId): void
    {
    }

    public function prepare(int $targetId): void
    {
        self::$prepared[] = $targetId;
    }

    public function owns(User $seller, int $targetId): bool
    {
        return intdiv($targetId, 100) === (int) $seller->id;
    }

    public function searchOwn(User $seller, string $query, int $limit): array
    {
        return [['id' => $seller->id * 100 + 1, 'label' => 'Item '.($seller->id * 100 + 1)]];
    }
}
