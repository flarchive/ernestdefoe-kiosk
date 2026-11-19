<?php

namespace Ernestdefoe\Kiosk\Unlock;

/** Posting in a tag: a single listing (one-time price) or a posting pass (a set time or renewing). */
class PostInTagUnlock extends TagCreditUnlock
{
    public function label(): string
    {
        return 'ernestdefoe-kiosk.lib.unlock_types.post';
    }

    public function icon(): string
    {
        return 'fas fa-pen-to-square';
    }
}
