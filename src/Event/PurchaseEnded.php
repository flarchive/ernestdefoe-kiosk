<?php

namespace Ernestdefoe\Kiosk\Event;

use Ernestdefoe\Kiosk\Model\Purchase;

class PurchaseEnded
{
    public function __construct(public Purchase $purchase)
    {
    }
}
