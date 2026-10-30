<?php

namespace Ernestdefoe\Kiosk\Unlock;

/** Tips and bounties in a tag: a Back this button on its discussions, with a pay-what-you-want price. */
class TipUnlock extends TagCreditUnlock
{
    public function label(): string
    {
        return 'ernestdefoe-kiosk.lib.unlock_types.tip';
    }

    public function icon(): string
    {
        return 'fas fa-hand-holding-heart';
    }
}
