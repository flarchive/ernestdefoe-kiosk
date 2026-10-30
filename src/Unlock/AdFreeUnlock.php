<?php

namespace Ernestdefoe\Kiosk\Unlock;

use Flarum\Locale\TranslatorInterface;
use Flarum\User\User;

/**
 * "No ads": a product that includes it hides every Kiosk ad from the buyer
 * while they hold it. There is one item, id 1. Holding it is the whole
 * effect (Ads::adFree asks Access), so there is nothing to grant or revoke.
 */
class AdFreeUnlock implements UnlockType
{
    public function __construct(private TranslatorInterface $translator)
    {
    }

    public function label(): string
    {
        return 'ernestdefoe-kiosk.lib.unlock_types.ads';
    }

    public function icon(): string
    {
        return 'fas fa-eye-slash';
    }

    public function search(User $actor, string $query, int $limit): array
    {
        return [['id' => 1, 'label' => $this->translator->trans('ernestdefoe-kiosk.lib.ad_free')]];
    }

    public function describe(array $ids): array
    {
        return in_array(1, $ids, true) ? [1 => ['label' => $this->translator->trans('ernestdefoe-kiosk.lib.ad_free'), 'url' => null]] : [];
    }

    public function grant(User $user, int $targetId): void
    {
    }

    public function revoke(User $user, int $targetId): void
    {
    }

    public function prepare(int $targetId): void
    {
    }
}
