<?php

use Flarum\Database\Migration;

/*
 * How a paid tag is shown to people who haven't bought it.
 *
 * teaser = 1: the tag, its discussions and their titles stay visible; each
 * discussion's text is a faded excerpt with an offer, and replies are hidden.
 * teaser = 0: the tag is restricted and hidden, shown only as a locked tile.
 *
 * Existing rows were made restricted, so they start as hidden (0), with
 * restricted_by_kiosk = 1: Kiosk may undo a restriction only it added.
 */
return Migration::addColumns('kiosk_tag_groups', [
    'teaser' => ['boolean', 'default' => false],
    'restricted_by_kiosk' => ['boolean', 'default' => true],
]);
