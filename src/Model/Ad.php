<?php

namespace Ernestdefoe\Kiosk\Model;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;

/**
 * @property int $id
 * @property string $name
 * @property string $location
 * @property string $kind
 * @property string|null $image_path
 * @property int|null $image_width
 * @property int|null $image_height
 * @property string|null $link_url
 * @property string|null $alt_text
 * @property string|null $html
 * @property string $audience
 * @property bool $is_active
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Ad extends AbstractModel
{
    public const IMAGE = 'image';
    public const HTML = 'html';

    /**
     * Where an ad can go. The first six are Ad Management's zones, under the
     * same names; the last three are new. See js/src/forum/ads.tsx.
     */
    public const LOCATIONS = [
        'header',
        'below_header',
        'between_posts',
        'sidebar',
        'above_footer',
        'footer',
        'between_discussions',
        'after_first_post',
        'discussion_sidebar',
    ];

    public const AUDIENCES = ['everyone', 'guests', 'members'];

    protected $table = 'kiosk_ads';

    public $timestamps = true;

    protected $casts = [
        'is_active' => 'bool',
        'image_width' => 'int',
        'image_height' => 'int',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
