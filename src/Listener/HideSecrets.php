<?php

namespace Ernestdefoe\Kiosk\Listener;

use Flarum\Settings\Event\Deserializing;

/**
 * 🚨 Flarum sends every setting to the admin page, so a secret saved as a
 * setting would sit in the page source of every admin session. The keys are
 * write-only: the admin page is told whether one is saved, never what it is.
 */
class HideSecrets
{
    public function handle(Deserializing $event): void
    {
        foreach (array_keys($event->settings) as $key) {
            if (str_starts_with($key, 'ernestdefoe-kiosk.') && str_ends_with($key, '_secret')) {
                unset($event->settings[$key]);
            }
        }
    }
}
