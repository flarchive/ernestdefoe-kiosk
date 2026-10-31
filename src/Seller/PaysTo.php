<?php

namespace Ernestdefoe\Kiosk\Seller;

use Ernestdefoe\Kiosk\Model\Payment;
use Ernestdefoe\Kiosk\Model\Purchase;
use Illuminate\Database\Eloquent\Builder;

/**
 * For providers: who the money goes to. Unbound, the forum; bound with
 * forAccount(), one member's account.
 *
 * 🚨 Every lookup a provider makes from something the outside world sent (a
 * webhook, a return URL) goes through ownPurchases()/ownPayments(), so an
 * event arriving through one member's account can never open, renew or
 * refund a purchase paid to someone else — not even a forged one, and not
 * the forum's own.
 */
trait PaysTo
{
    protected ?Account $payee = null;

    public function account(): ?Account
    {
        return $this->payee;
    }

    /** @return Builder<Purchase> */
    protected function ownPurchases(): Builder
    {
        $query = Purchase::query()->where('provider', $this->key());

        if ($this->payee) {
            return $query->where('seller_account_id', $this->payee->id);
        }

        // The forum's own: and members it takes payments for through its own
        // account (PayPal email payees), whose events come to its endpoint.
        $hosted = $this->hostedAccounts();

        return $hosted
            ? $query->where(fn ($q) => $q->whereNull('seller_account_id')->orWhereIn('seller_account_id', $hosted))
            : $query->whereNull('seller_account_id');
    }

    /** Member accounts paid through the forum's own account, as an id subquery. @return \Illuminate\Database\Eloquent\Builder<Account>|null */
    protected function hostedAccounts(): ?Builder
    {
        return null;
    }

    /** @return Builder<Payment> */
    protected function ownPayments(): Builder
    {
        return Payment::query()->where('provider', $this->key())->whereIn('purchase_id', $this->ownPurchases()->select('id'));
    }

    /** The key a provider-side object (a PayPal plan, a Stripe price) is stored under for this payee. */
    protected function refKey(string $base): string
    {
        return $this->payee ? $base.'@'.$this->payee->id : $base;
    }
}
