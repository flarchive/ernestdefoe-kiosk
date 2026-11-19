<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/*
 * "One per member": a product a member can't buy again while they hold it.
 * On for products that sell access (a group, a tag, a course, a file); off
 * for what is used up (posting credits, bumps, tips) and pay-what-you-want
 * prices. Existing products are set the same way.
 */
return [
    'up' => function (Builder $schema) {
        if (! $schema->hasColumn('kiosk_products', 'one_per_member')) {
            $schema->table('kiosk_products', fn (Blueprint $table) => $table->boolean('one_per_member')->default(false));
        }

        $db = $schema->getConnection();
        $db->table('kiosk_products')
            ->whereNotIn('id', fn ($q) => $q->select('product_id')->from('kiosk_product_unlocks')->whereIn('type', ['post', 'bump', 'tip']))
            ->whereNotIn('id', fn ($q) => $q->select('product_id')->from('kiosk_prices')->where('kind', 'custom'))
            ->update(['one_per_member' => true]);
    },
    'down' => function (Builder $schema) {
        $schema->table('kiosk_products', fn (Blueprint $table) => $table->dropColumn('one_per_member'));
    },
];
