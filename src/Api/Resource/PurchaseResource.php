<?php

namespace Ernestdefoe\Kiosk\Api\Resource;

use Ernestdefoe\Kiosk\Model\Purchase;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;

/**
 * The purchase as a notification's subject, and nothing more: no endpoints.
 * 🚨 Without a registered resource for a notification's subject, every
 * notification on the forum fails to load, not only Kiosk's.
 *
 * @extends AbstractDatabaseResource<Purchase>
 */
class PurchaseResource extends AbstractDatabaseResource
{
    public function type(): string
    {
        return 'kiosk-purchases';
    }

    public function model(): string
    {
        return Purchase::class;
    }

    public function endpoints(): array
    {
        return [];
    }

    public function fields(): array
    {
        return [
            Schema\Str::make('number'),
            Schema\Str::make('productName')->property('product_name'),
        ];
    }
}
