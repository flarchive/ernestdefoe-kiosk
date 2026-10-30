<?php

namespace Ernestdefoe\Kiosk\Model;

use Flarum\Database\AbstractModel;

/**
 * @property int $id
 * @property int $product_id
 * @property string $type
 * @property int $target_id
 */
class ProductUnlock extends AbstractModel
{
    protected $table = 'kiosk_product_unlocks';

    protected $casts = ['product_id' => 'int', 'target_id' => 'int'];
}
