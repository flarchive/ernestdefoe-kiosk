<?php

namespace Ernestdefoe\Kiosk\Console;

use Ernestdefoe\Kiosk\Import\FloxumImporter;
use Ernestdefoe\Kiosk\Provider\StripeClient;
use Flarum\Console\AbstractCommand;
use Symfony\Component\Console\Input\InputOption;

/**
 * `php flarum kiosk:import-floxum [--dry-run]` — move from floxum/payments.
 *
 * Connect Kiosk to the SAME Stripe account first (Kiosk → Payments), so the
 * subscriptions it adopts keep billing and their events reach Kiosk.
 */
class ImportFloxumCommand extends AbstractCommand
{
    public function __construct(private FloxumImporter $importer, private StripeClient $stripe)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('kiosk:import-floxum')
            ->setDescription('Import products, subscriptions, pay-to-view readers and bounties from floxum/payments.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Say what would be imported, and change nothing.');
    }

    protected function fire(): int
    {
        if (! $this->importer->found()) {
            $this->error('floxum/payments has never been installed here: there is nothing to import.');

            return 1;
        }
        if (! $this->stripe->isConnected()) {
            $this->error('Connect Stripe in Kiosk first (Admin → Kiosk → Payments), with the same Stripe account floxum/payments uses.');

            return 1;
        }

        $dry = (bool) $this->input->getOption('dry-run');
        $counts = $this->importer->run($dry);

        $this->info(($dry ? 'Would import' : 'Imported').": {$counts['products']} products, {$counts['subscriptions']} subscriptions, {$counts['readers']} pay-to-view readers, {$counts['tips']} bounties as tips ({$counts['skipped']} products were already imported).");
        foreach ($this->importer->notes as $note) {
            $this->info('  · '.$note);
        }
        if (! $dry) {
            $this->info('Next: check the new products in Kiosk, then disable floxum/payments and delete its webhook endpoint in Stripe. Members keep their groups; Stripe keeps billing their subscriptions.');
        }

        return 0;
    }
}
