<?php

use Flarum\Database\Migration;

/*
 * What a purchase was for, when it was for something particular: a tip
 * backs one discussion. Null for everything else.
 */
return Migration::addColumns('kiosk_purchases', [
    'context_type' => ['string', 'length' => 20, 'nullable' => true],
    'context_id' => ['integer', 'unsigned' => true, 'nullable' => true],
]);
