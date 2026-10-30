<?php

namespace Ernestdefoe\Kiosk\Unlock;

use Flarum\Http\UrlGenerator;
use Flarum\Tags\Tag;
use Flarum\User\User;

/**
 * Something done in a tag that is paid for: posting there, bumping a
 * discussion there, backing a discussion there. The entitlement is the
 * credit (or, for posting, a pass for its period); what spends it lives in
 * Service\Credits. Nothing is written when somebody buys.
 */
abstract class TagCreditUnlock implements UnlockType
{
    public function __construct(protected UrlGenerator $url)
    {
    }

    public function search(User $actor, string $query, int $limit): array
    {
        return Tag::query()
            ->when($query !== '', fn ($q) => $q->where('name', 'like', '%'.addcslashes($query, '%_\\').'%'))
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'name'])
            ->map(fn (Tag $t) => ['id' => (int) $t->id, 'label' => (string) $t->name])
            ->all();
    }

    public function describe(array $ids): array
    {
        return Tag::query()->whereIn('id', $ids)->get(['id', 'name', 'slug'])
            ->mapWithKeys(fn (Tag $t) => [(int) $t->id => [
                'label' => (string) $t->name,
                'url' => $this->url->to('forum')->route('tag', ['slug' => $t->slug]),
            ]])
            ->all();
    }

    public function grant(User $user, int $targetId): void
    {
    }

    public function revoke(User $user, int $targetId): void
    {
    }

    public function prepare(int $targetId): void
    {
        resolve(\Ernestdefoe\Kiosk\Service\Credits::class)->forget();
    }
}
