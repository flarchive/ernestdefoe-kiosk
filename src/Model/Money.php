<?php

namespace Ernestdefoe\Kiosk\Model;

use Carbon\Carbon;

/** Amounts are stored as integers in the currency's smallest unit. */
final class Money
{
    /** Currencies PayPal (and Stripe) take with no decimals. */
    private const ZERO_DECIMAL = ['JPY', 'HUF', 'TWD', 'KRW', 'CLP', 'VND', 'ISK', 'UGX', 'XAF', 'XOF'];

    public const CURRENCIES = ['USD', 'EUR', 'GBP', 'CAD', 'AUD', 'NZD', 'CHF', 'SEK', 'NOK', 'DKK', 'PLN', 'CZK', 'HUF', 'JPY', 'MXN', 'BRL', 'SGD', 'HKD', 'TWD', 'ILS', 'PHP', 'THB', 'MYR'];

    public static function decimals(string $currency): int
    {
        return in_array(strtoupper($currency), self::ZERO_DECIMAL, true) ? 0 : 2;
    }

    /** 1999 USD → "19.99"; 1999 JPY → "1999". What a provider's API takes. */
    public static function toDecimal(int $minor, string $currency): string
    {
        $d = self::decimals($currency);

        return $d === 0 ? (string) $minor : number_format($minor / 100, 2, '.', '');
    }

    /**
     * "19.99" → 1999. Rounded, because 19.99 * 100 is 1998.9999999999998 in
     * floating point and truncating it charges a cent less.
     */
    public static function toMinor(mixed $value, string $currency): ?int
    {
        if (! is_numeric($value) || (float) $value < 0) {
            return null;
        }

        return (int) round((float) $value * (self::decimals($currency) === 0 ? 1 : 100));
    }

    /**
     * Calendar intervals, not 30-day months: renewing on the 31st of a
     * 30-day month must not drift the renewal date forward every month.
     */
    public static function addInterval(Carbon $from, string $interval, int $count): Carbon
    {
        $at = $from->copy();

        return match ($interval) {
            'day' => $at->addDays($count),
            'week' => $at->addWeeks($count),
            'year' => $at->addYearsNoOverflow($count),
            default => $at->addMonthsNoOverflow($count),
        };
    }
}
