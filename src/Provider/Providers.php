<?php

namespace Ernestdefoe\Kiosk\Provider;

use Ernestdefoe\Kiosk\Model\Purchase;
use Ernestdefoe\Kiosk\Seller\Account;
use Ernestdefoe\Kiosk\Seller\SellerProvider;
use Illuminate\Contracts\Container\Container;

/** The payment providers this forum can use, by key. */
class Providers
{
    /** @var array<string, Provider> */
    private array $resolved = [];

    /** @param array<string, class-string<Provider>> $providers */
    public function __construct(private Container $container, private array $providers)
    {
    }

    public function get(string $key): ?Provider
    {
        if (! isset($this->providers[$key])) {
            return null;
        }

        return $this->resolved[$key] ??= $this->container->make($this->providers[$key]);
    }

    /** The provider bound to a member's payout account, or null if it can't take one. */
    public function forAccount(Account $account): ?Provider
    {
        $provider = $this->get($account->provider);

        return $provider instanceof SellerProvider ? $provider->forAccount($account) : null;
    }

    /**
     * The provider as it was when this purchase was paid: the forum's, or the
     * member's account the money went to. Everything after checkout (confirm,
     * cancel, refund, reconcile) must go through here.
     */
    public function forPurchase(Purchase $purchase): ?Provider
    {
        if (! $purchase->seller_account_id) {
            return $this->get($purchase->provider);
        }

        $account = Account::query()->find($purchase->seller_account_id);

        return $account && $account->provider === $purchase->provider ? $this->forAccount($account) : null;
    }

    /** @return list<Provider> those ready to take money now */
    public function ready(): array
    {
        $out = [];
        foreach (array_keys($this->providers) as $key) {
            $provider = $this->get($key);
            if ($provider && $provider->isReady()) {
                $out[] = $provider;
            }
        }

        return $out;
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->providers);
    }
}
