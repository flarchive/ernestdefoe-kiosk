<?php

namespace Ernestdefoe\Kiosk\Model;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string|null $benefits
 * @property string|null $icon
 * @property string|null $color
 * @property bool $is_active
 * @property bool $is_hidden
 * @property string|null $buyer_group_ids
 * @property int|null $stock
 * @property int $sold_count
 * @property Carbon|null $available_from
 * @property Carbon|null $available_until
 * @property int $position
 * @property-read \Illuminate\Database\Eloquent\Collection<int, ProductUnlock> $unlocks
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Price> $prices
 */
class Product extends AbstractModel
{
    protected $table = 'kiosk_products';

    public $timestamps = true;

    protected $casts = [
        'is_active' => 'bool',
        'is_hidden' => 'bool',
        'stock' => 'int',
        'sold_count' => 'int',
        'position' => 'int',
        'available_from' => 'datetime',
        'available_until' => 'datetime',
    ];

    /** @return HasMany<ProductUnlock, $this> */
    public function unlocks(): HasMany
    {
        return $this->hasMany(ProductUnlock::class);
    }

    /** Prices on sale now, in the admin's order. @return HasMany<Price, $this> */
    public function prices(): HasMany
    {
        return $this->hasMany(Price::class)->whereNull('archived_at')->orderBy('position')->orderBy('id');
    }

    /** @return list<int> */
    public function buyerGroupIds(): array
    {
        $ids = json_decode((string) $this->buyer_group_ids, true);

        return is_array($ids) ? array_values(array_map('intval', $ids)) : [];
    }

    /** @return list<string> */
    public function benefitLines(): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $this->benefits) ?: [])));
    }

    /**
     * Why this person cannot buy it now, or null if they can. The same answer
     * the store shows and checkout enforces, so the two can never disagree.
     */
    public function refusal(User $actor): ?string
    {
        $now = Carbon::now();

        if (! $this->is_active || ($this->available_from && $this->available_from->isAfter($now))) {
            return 'unavailable';
        }
        if ($this->available_until && $this->available_until->isBefore($now)) {
            return 'unavailable';
        }
        if ($this->stock !== null && $this->sold_count >= $this->stock) {
            return 'sold_out';
        }
        if ($actor->isGuest()) {
            return 'login';
        }
        $groups = $this->buyerGroupIds();
        if ($groups && ! $actor->isAdmin() && ! $actor->groups->pluck('id')->intersect($groups)->count()) {
            return 'not_eligible';
        }

        return null;
    }
}
