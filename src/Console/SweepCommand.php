<?php

namespace Ernestdefoe\Kiosk\Console;

use Ernestdefoe\Kiosk\Service\Sweeper;
use Carbon\Carbon;
use Flarum\Console\AbstractCommand;
use Flarum\Settings\SettingsRepositoryInterface;

/**
 * `php flarum kiosk:sweep` — scheduled every ten minutes.
 *
 * 🚨 configure() with setName(), and fire() returning int: a nameless
 * command or a void fire() takes down EVERY `php flarum` command.
 */
class SweepCommand extends AbstractCommand
{
    public function __construct(private Sweeper $sweeper, private SettingsRepositoryInterface $settings)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('kiosk:sweep')->setDescription('Close lapsed access, expire abandoned checkouts, send renewal reminders and catch missed payment webhooks.');
    }

    protected function fire(): int
    {
        $out = $this->sweeper->run();
        // The admin checklist reads this to say whether the scheduler runs.
        $this->settings->set('ernestdefoe-kiosk.last_sweep_at', Carbon::now()->toIso8601String());
        $this->info(implode(', ', array_map(fn ($k, $v) => "$k: $v", array_keys($out), $out)));

        return 0;
    }
}
