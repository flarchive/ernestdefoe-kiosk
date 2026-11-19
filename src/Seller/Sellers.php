<?php

namespace Ernestdefoe\Kiosk\Seller;

use Ernestdefoe\Kiosk\Provider\Providers;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;

/**
 * Members who sell: whether selling is on, who may, and which of their payout
 * accounts can take a buyer's money right now.
 */
class Sellers
{
    public const ENABLED = 'ernestdefoe-kiosk.sellers';

    /** Providers members may connect, comma-separated (empty = every one the forum can bind). */
    public const PROVIDERS = 'ernestdefoe-kiosk.seller_providers';

    /** New listings and price changes wait for a moderator unless the seller has this. */
    public const TRUSTED = 'kiosk.sellTrusted';

    public const PERMISSION = 'kiosk.sell';

    /** @var array<int, array<string, Account>> seller id => provider => account */
    private array $ready = [];

    public function __construct(private SettingsRepositoryInterface $settings, private Providers $providers)
    {
    }

    public function enabled(): bool
    {
        return (bool) $this->settings->get(self::ENABLED);
    }

    public function maySell(User $user): bool
    {
        return $this->enabled() && $user->exists && $user->hasPermission(self::PERMISSION);
    }

    /** @return list<string> provider keys members may connect */
    public function providerKeys(): array
    {
        $chosen = array_filter(array_map('trim', explode(',', (string) $this->settings->get(self::PROVIDERS))));
        $bindable = array_values(array_filter($this->providers->keys(), function (string $key) {
            $provider = $this->providers->get($key);
            // Stripe hangs members' accounts off the forum's own, with its Connect endpoint made.
            if ($provider instanceof \Ernestdefoe\Kiosk\Provider\Stripe) {
                return $provider->isReady() && $provider->client()->setting('connect_webhook_secret') !== '';
            }

            return $provider instanceof SellerProvider;
        }));

        return $chosen ? array_values(array_intersect($bindable, $chosen)) : $bindable;
    }

    /**
     * The mode a member's account with this provider is in: the forum's when
     * the forum uses that provider itself, else live. (Only Stripe needs the
     * forum's own account: it is the platform members' accounts hang off.)
     */
    public function modeFor(string $provider): string
    {
        $forum = $this->providers->get($provider);

        if ($provider === 'stripe' || ($forum && $forum->isReady())) {
            return $forum ? $forum->mode() : 'live';
        }

        return 'live';
    }

    /**
     * What a buyer may pay a seller with, for a product card.
     *
     * @param array<string, Account> $accounts
     * @return list<array{key: string, mode: string, recurring: bool}>
     */
    public function offers(array $accounts): array
    {
        $out = [];
        foreach ($accounts as $account) {
            if ($bound = $this->providers->forAccount($account)) {
                $out[] = ['key' => $account->provider, 'mode' => $account->mode, 'recurring' => $bound->supports(\Ernestdefoe\Kiosk\Model\Price::monthly())];
            }
        }

        return $out;
    }

    /**
     * Each seller's accounts that can take money now, in the mode the forum's
     * provider is in. One query for any number of sellers.
     *
     * @param list<int> $sellerIds
     * @return array<int, array<string, Account>>
     */
    public function readyAccounts(array $sellerIds): array
    {
        $missing = array_values(array_diff(array_unique($sellerIds), array_keys($this->ready)));

        if ($missing && $this->enabled()) {
            $keys = $this->providerKeys();
            foreach ($missing as $id) {
                $this->ready[$id] = [];
            }
            $rows = Account::query()->whereIn('user_id', $missing)->where('status', Account::CONNECTED)->whereIn('provider', $keys ?: ['-'])->get();
            foreach ($rows as $account) {
                $bound = $this->modeFor($account->provider) === $account->mode ? $this->providers->forAccount($account) : null;
                if ($bound && $bound->isReady()) {
                    $this->ready[$account->user_id][$account->provider] = $account;
                }
            }
        }

        return array_intersect_key($this->ready, array_flip($sellerIds));
    }

    public function account(int $sellerId, string $provider): ?Account
    {
        return $this->readyAccounts([$sellerId])[$sellerId][$provider] ?? null;
    }
}
