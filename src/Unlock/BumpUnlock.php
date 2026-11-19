<?php

namespace Ernestdefoe\Kiosk\Unlock;

/** Bumps in a tag: each purchase bumps one of the buyer's own discussions to the top. */
class BumpUnlock extends TagCreditUnlock
{
    public function label(): string
    {
        return 'ernestdefoe-kiosk.lib.unlock_types.bump';
    }

    public function icon(): string
    {
        return 'fas fa-arrow-up';
    }
}
