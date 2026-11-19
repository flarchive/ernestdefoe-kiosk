<?php

namespace Ernestdefoe\Kiosk\Event;

use Ernestdefoe\Kiosk\Model\Purchase;

class PurchaseActivated
{
    public function __construct(public Purchase $purchase)
    {
    }
}
