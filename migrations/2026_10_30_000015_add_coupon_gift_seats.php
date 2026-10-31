<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Builder;

/*
 * Coupons, gifts and team seats on purchases and prices; and entitlements
 * unique per user, so a team purchase holds one per seat.
 */
return [
    // 🚨 MySQL can't roll DDL back, so each step checks it isn't done yet:
    // a run that stopped halfway finishes on the next one.
    'up' => function (Builder $schema) {
        if (! $schema->hasColumn('kiosk_prices', 'seats')) {
            $schema->table('kiosk_prices', function ($table) {
                $table->unsignedSmallInteger('seats')->default(1);
            });
        }
        if (! $schema->hasColumn('kiosk_purchases', 'seats')) {
            $schema->table('kiosk_purchases', function ($table) {
                $table->string('coupon_code', 40)->nullable();
                $table->unsignedInteger('gifted_by')->nullable();
                $table->unsignedSmallInteger('seats')->default(1);
            });
        }
        if (! $schema->hasTable('kiosk_seats')) {
            $schema->create('kiosk_seats', function ($table) {
                $table->unsignedInteger('purchase_id');
                $table->unsignedInteger('user_id');
                $table->dateTime('added_at');
                $table->primary(['purchase_id', 'user_id']);
                $table->foreign('purchase_id')->references('id')->on('kiosk_purchases')->cascadeOnDelete();
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            });
        }
        // 🚨 New index first: on MySQL the old one backs the purchase_id
        // foreign key and can't be dropped until another index covers it.
        if (! $schema->hasIndex('kiosk_entitlements', ['purchase_id', 'user_id', 'type', 'target_id'], 'unique')) {
            $schema->table('kiosk_entitlements', function ($table) {
                // 🚨 Named: the generated name plus a table prefix passes MySQL's 64 characters.
                $table->unique(['purchase_id', 'user_id', 'type', 'target_id'], 'kiosk_entitlements_holder_unique');
            });
        }
        if ($schema->hasIndex('kiosk_entitlements', ['purchase_id', 'type', 'target_id'], 'unique')) {
            $schema->table('kiosk_entitlements', function ($table) {
                $table->dropUnique(['purchase_id', 'type', 'target_id']);
            });
        }
    },
    'down' => function (Builder $schema) {
        $schema->table('kiosk_entitlements', function ($table) {
            $table->unique(['purchase_id', 'type', 'target_id']);
        });
        $schema->table('kiosk_entitlements', function ($table) {
            $table->dropUnique('kiosk_entitlements_holder_unique');
        });
        $schema->dropIfExists('kiosk_seats');
        $schema->table('kiosk_purchases', function ($table) {
            $table->dropColumn(['coupon_code', 'gifted_by', 'seats']);
        });
        $schema->table('kiosk_prices', function ($table) {
            $table->dropColumn('seats');
        });
    },
];
