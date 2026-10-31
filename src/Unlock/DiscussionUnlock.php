<?php

namespace Ernestdefoe\Kiosk\Unlock;

use Flarum\Discussion\Discussion;
use Flarum\Http\UrlGenerator;
use Flarum\User\User;

/**
 * One discussion, sold by itself ("pay to view"): an article, a guide, a
 * review. Everyone sees its title and the opening words; buying reveals the
 * rest and lets them reply. Access is computed (see Gate), so nothing is
 * written when somebody buys.
 */
class DiscussionUnlock implements UnlockType
{
    public function __construct(private UrlGenerator $url)
    {
    }

    public function label(): string
    {
        return 'ernestdefoe-kiosk.lib.unlock_types.discussion';
    }

    public function icon(): string
    {
        return 'fas fa-file-lines';
    }

    public function search(User $actor, string $query, int $limit): array
    {
        return Discussion::query()
            ->whereNull('hidden_at')
            ->when($query !== '', fn ($q) => ctype_digit($query)
                ? $q->whereKey((int) $query)
                : $q->where('title', 'like', '%'.addcslashes($query, '%_\\').'%'))
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'title'])
            ->map(fn (Discussion $d) => ['id' => (int) $d->id, 'label' => (string) $d->title])
            ->all();
    }

    public function describe(array $ids): array
    {
        return Discussion::query()->whereIn('id', $ids)->get(['id', 'title', 'slug'])
            ->mapWithKeys(fn (Discussion $d) => [(int) $d->id => [
                'label' => (string) $d->title,
                'url' => $this->url->to('forum')->route('discussion', ['id' => $d->id.($d->slug ? '-'.$d->slug : '')]),
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
        resolve(\Ernestdefoe\Kiosk\Service\Gate::class)->forget();
    }
}
