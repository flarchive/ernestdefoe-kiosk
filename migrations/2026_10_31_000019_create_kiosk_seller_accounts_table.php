<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * A member's own payout account with one provider: where their buyers' money
 * goes. `secrets` is sealed by Vault (never readable from the database alone);
 * `details` holds what may be shown, such as "ends in …4F2A" or a Stripe
 * account id. One account per member, provider and mode.
 */
return Migration::createTable('kiosk_seller_accounts', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedInteger('user_id');
    $table->string('provider', 20);
    $table->string('mode', 8);
    $table->string('status', 12);
    $table->text('secrets')->nullable();
    $table->text('details')->nullable();
    $table->string('external_id', 191)->nullable();
    $table->dateTime('created_at');
    $table->dateTime('updated_at');

    $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
    $table->unique(['user_id', 'provider', 'mode'], 'kiosk_seller_accounts_unique');
    $table->index('external_id', 'kiosk_seller_accounts_external');
});
