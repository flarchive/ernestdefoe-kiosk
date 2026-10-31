<?php

namespace Ernestdefoe\Kiosk\Seller;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A member's payout account with one provider (see the migration).
 *
 * @property int $id
 * @property int $user_id
 * @property string $provider
 * @property string $mode
 * @property string $status
 * @property string|null $secrets
 * @property array<string, mixed>|null $details
 * @property string|null $external_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User|null $user
 */
class Account extends AbstractModel
{
    /** Ready to take buyers' money. */
    public const CONNECTED = 'connected';

    /** Started but not finished (Stripe's sign-up, a PayPal webhook not made yet). */
    public const PENDING = 'pending';

    protected $table = 'kiosk_seller_accounts';

    protected $guarded = [];

    public $timestamps = true;

    protected $casts = ['details' => 'array', 'created_at' => 'datetime', 'updated_at' => 'datetime'];

    /** @var array<string, string>|null */
    private ?array $opened = null;

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array<string, string> */
    public function credentials(): array
    {
        return $this->opened ??= resolve(Vault::class)->open($this->secrets);
    }

    /** @param array<string, string> $secrets */
    public function setCredentials(array $secrets): void
    {
        $this->secrets = resolve(Vault::class)->seal($secrets);
        $this->opened = $secrets;
    }

    public function isConnected(): bool
    {
        return $this->status === self::CONNECTED;
    }

    /** The provider's id of this account (Stripe's acct_…), when it has one. */
    public function externalId(): string
    {
        return (string) $this->external_id;
    }
}
