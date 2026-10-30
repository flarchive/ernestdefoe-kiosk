<?php

namespace Ernestdefoe\Kiosk\Event;

use Ernestdefoe\Kiosk\Model\Purchase;

class PurchaseCanceled
{
    public function __construct(public Purchase $purchase)
    {
    }
}
