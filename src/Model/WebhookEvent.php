<?php

namespace Ernestdefoe\Kiosk\Model;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;

/**
 * @property int $id
 * @property string $provider
 * @property string $event_id
 * @property string $type
 * @property string $payload
 * @property Carbon $received_at
 * @property Carbon|null $processed_at
 * @property string|null $error
 * @property int|null $account_id
 */
class WebhookEvent extends AbstractModel
{
    protected $table = 'kiosk_webhook_events';

    protected $casts = [
        'received_at' => 'datetime',
        'processed_at' => 'datetime',
    ];
}
