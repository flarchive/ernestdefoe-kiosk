<?php

namespace Ernestdefoe\Kiosk\Provider;

use Ernestdefoe\Kiosk\Model\Payment;
use Ernestdefoe\Kiosk\Model\Price;
use Ernestdefoe\Kiosk\Model\Product;
use Ernestdefoe\Kiosk\Model\Purchase;
use Ernestdefoe\Kiosk\Seller\Account;
use Ernestdefoe\Kiosk\Seller\PaysTo;
use Ernestdefoe\Kiosk\Seller\SellerProvider;
use Ernestdefoe\Kiosk\Service\Transfers;
use Flarum\Foundation\ValidationException;
use Flarum\Locale\TranslatorInterface;
use Flarum\Settings\SettingsRepositoryInterface;

/**
 * Manual bank transfer. Nothing is called: the buyer is shown the account to
 * pay into and a reference, pays from their own bank, and the order waits
 * until the admin (or, for a member's listing, the seller) sees the money and
 * presses Mark paid. See Service\Transfers.
 *
 * The forum's account details are settings (so its admins see them); a
 * seller's are sealed in their payout account like any other secret. Neither
 * is ever sent to the forum payload: the API sends them to the payer of an
 * order and nobody else.
 */
class Bank implements Provider, SellerProvider
{
    use PaysTo;

    public const KEY = 'bank';

    public function __construct(
        private SettingsRepositoryInterface $settings,
        private Transfers $transfers,
        private TranslatorInterface $translator,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function forAccount(Account $account): static
    {
        $copy = clone $this;
        $copy->payee = $account;

        return $copy;
    }

    /** @return array{details: string, instructions: string} where the buyer sends the money */
    public function payTo(): array
    {
        if ($this->payee) {
            $saved = $this->payee->credentials();

            return ['details' => trim($saved['details'] ?? ''), 'instructions' => trim($saved['instructions'] ?? '')];
        }

        return [
            'details' => trim((string) $this->settings->get('ernestdefoe-kiosk.bank_details')),
            'instructions' => trim((string) $this->settings->get('ernestdefoe-kiosk.bank_instructions')),
        ];
    }

    public function isReady(): bool
    {
        if (! $this->payee && ! $this->settings->get('ernestdefoe-kiosk.bank_enabled')) {
            return false;
        }

        return $this->payTo()['details'] !== '';
    }

    /** Real money only: there is no test bank. */
    public function mode(): string
    {
        return 'live';
    }

    /** Paid once by the buyer: nothing can bill them again. */
    public function supports(Price $price): bool
    {
        return ! $price->isRecurring();
    }

    /** The order waits for the money; the buyer goes to its page to see how to pay. */
    public function checkout(Purchase $purchase, Price $price, Product $product, string $returnUrl, string $cancelUrl): string
    {
        $this->transfers->await($purchase);

        return $returnUrl;
    }

    /** Nothing to ask: a person confirms the money arrived. */
    public function confirm(Purchase $purchase): void
    {
    }

    public function reconcile(Purchase $purchase): void
    {
    }

    public function cancel(Purchase $purchase): bool
    {
        return false;
    }

    /** The money is sent back from the bank by hand; Refunds records it. */
    public function refund(Payment $payment): bool
    {
        return false;
    }

    public function switchPlan(Purchase $purchase, Price $to, string $returnUrl): ?string
    {
        throw new ValidationException(['purchase' => $this->translator->trans('ernestdefoe-kiosk.forum.errors.cannot_switch')]);
    }
}
