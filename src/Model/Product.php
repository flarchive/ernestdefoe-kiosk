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
 * @property bool $one_per_member
 * @property string|null $buyer_group_ids
 * @property int|null $stock
 * @property int $sold_count
 * @property int $reserved_count held for bank transfers not yet paid
 * @property Carbon|null $available_from
 * @property Carbon|null $available_until
 * @property int $position
 * @property int|null $seller_id
 * @property string|null $review
 * @property string|null $review_note
 * @property-read User|null $seller
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
        'one_per_member' => 'bool',
        'stock' => 'int',
        'sold_count' => 'int',
        'reserved_count' => 'int',
        'position' => 'int',
        'available_from' => 'datetime',
        'available_until' => 'datetime',
    ];

    /** A member's listing waiting for a moderator, or turned down. */
    public const PENDING = 'pending';

    public const REJECTED = 'rejected';

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<User, $this> */
    public function seller(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

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

    /** Unlock types that are used up, so buying again is normal: posting credits, bumps, tips. */
    public const CONSUMABLE_TYPES = ['post', 'bump', 'tip'];

    /**
     * Whether a product with these unlocks and prices is "one per member" by
     * default: on when it only sells access, off for consumables and
     * pay-what-you-want prices. The admin can change it either way.
     *
     * @param list<string> $unlockTypes
     * @param list<string> $priceKinds
     */
    public static function onePerMemberByDefault(array $unlockTypes, array $priceKinds): bool
    {
        return ! array_intersect($unlockTypes, self::CONSUMABLE_TYPES) && ! in_array(Price::CUSTOM, $priceKinds, true);
    }

    /**
     * What this member already has of it: 'owned' (a live purchase: paid and
     * not ended, a one-time price forever), 'pending' (a bank transfer waiting
     * to be confirmed), or null. An unfinished card or PayPal checkout doesn't
     * count, so closing the payment tab never locks a member out. Nor does a
     * cancelled subscription still running to its end: subscribing again is
     * how a member changes their mind (one live subscription is checked apart).
     */
    public function heldBy(User $user): ?string
    {
        $held = Purchase::query()->where('user_id', $user->id)->where('product_id', $this->id)
            ->where(fn ($q) => $q->where('status', Purchase::AWAITING)
                ->orWhere(fn ($live) => $live->where('status', Purchase::ACTIVE)
                    ->where(fn ($p) => $p->whereNull('period_end')->orWhere('period_end', '>', Carbon::now()))))
            ->pluck('status')->all();

        return $held === [] ? null : (in_array(Purchase::AWAITING, $held, true) && count(array_unique($held)) === 1 ? 'pending' : 'owned');
    }

    /**
     * Why this person cannot buy it now, or null if they can. The same answer
     * the store shows and checkout enforces, so the two can never disagree.
     */
    public function refusal(User $actor): ?string
    {
        $now = Carbon::now();

        // A member's listing: only once a moderator let it through.
        if ($this->seller_id && $this->review !== null) {
            return 'unavailable';
        }

        if (! $this->is_active || ($this->available_from && $this->available_from->isAfter($now))) {
            return 'unavailable';
        }
        if ($this->available_until && $this->available_until->isBefore($now)) {
            return 'unavailable';
        }
        if ($this->stock !== null && $this->sold_count + $this->reserved_count >= $this->stock) {
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
