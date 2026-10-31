<?php

namespace Ernestdefoe\Kiosk\Seller;

/**
 * A provider that can take money into a member's own account as well as the
 * forum's. forAccount() returns a copy bound to that account: it checks out,
 * refunds and reads webhooks with the member's credentials, and only ever
 * touches purchases paid into that account.
 */
interface SellerProvider
{
    public function forAccount(Account $account): static;

    /** The member account this copy is bound to, or null for the forum's own. */
    public function account(): ?Account;
}
