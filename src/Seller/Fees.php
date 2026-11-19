<?php

namespace Ernestdefoe\Kiosk\Seller;

use Flarum\Settings\SettingsRepositoryInterface;

/**
 * The forum's cut of a member's sale. Stripe takes it automatically (an
 * application fee on the member's charge); PayPal and crypto pay the member
 * directly with nothing to take it from, so there it is always 0.
 */
class Fees
{
    public const PERCENT = 'ernestdefoe-kiosk.seller_fee_percent';

    public function __construct(private SettingsRepositoryInterface $settings)
    {
    }

    public function percent(): float
    {
        return min(50.0, max(0.0, round((float) $this->settings->get(self::PERCENT, 0), 2)));
    }

    public function cut(int $amountCents): int
    {
        return max(0, min($amountCents - 1, (int) round($amountCents * $this->percent() / 100)));
    }
}
