<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Listings by members. A product with a seller is theirs: their buyers pay
 * them directly, through the payout account the purchase records. `review`
 * is null once a listing may be sold, else pending or rejected. A payment
 * keeps the forum's cut of it (Stripe only).
 */
return [
    'up' => function (Builder $schema) {
        $schema->table('kiosk_products', function (Blueprint $table) {
            $table->unsignedInteger('seller_id')->nullable();
            $table->string('review', 10)->nullable();
            $table->string('review_note', 255)->nullable();
            $table->foreign('seller_id', 'kiosk_products_seller_foreign')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['seller_id', 'review'], 'kiosk_products_seller_index');
        });
        $schema->table('kiosk_purchases', function (Blueprint $table) {
            $table->unsignedInteger('seller_id')->nullable();
            $table->unsignedInteger('seller_account_id')->nullable();
            $table->index(['seller_id', 'created_at'], 'kiosk_purchases_seller_index');
        });
        $schema->table('kiosk_payments', function (Blueprint $table) {
            $table->unsignedInteger('fee_cents')->default(0);
        });
        $schema->table('kiosk_webhook_events', function (Blueprint $table) {
            $table->unsignedInteger('account_id')->nullable();
        });
    },
    'down' => function (Builder $schema) {
        $schema->table('kiosk_webhook_events', function (Blueprint $table) {
            $table->dropColumn('account_id');
        });
        $schema->table('kiosk_payments', function (Blueprint $table) {
            $table->dropColumn('fee_cents');
        });
        $schema->table('kiosk_purchases', function (Blueprint $table) {
            $table->dropIndex('kiosk_purchases_seller_index');
            $table->dropColumn(['seller_id', 'seller_account_id']);
        });
        $schema->table('kiosk_products', function (Blueprint $table) {
            $table->dropForeign('kiosk_products_seller_foreign');
            $table->dropIndex('kiosk_products_seller_index');
            $table->dropColumn(['seller_id', 'review', 'review_note']);
        });
    },
];
