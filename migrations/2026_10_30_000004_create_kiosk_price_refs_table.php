<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * A recurring price's twin at a payment provider (a PayPal billing plan), made
 * the first time someone subscribes at it, so the admin never makes one by hand.
 * `mode` keeps sandbox and live apart: a sandbox plan id means nothing live.
 */
return Migration::createTable('kiosk_price_refs', function (Blueprint $table) {
    $table->unsignedInteger('price_id');
    $table->string('provider', 20);
    $table->string('mode', 8);
    $table->string('external_id', 191);

    $table->primary(['price_id', 'provider', 'mode']);
    $table->foreign('price_id')->references('id')->on('kiosk_prices')->cascadeOnDelete();
});
