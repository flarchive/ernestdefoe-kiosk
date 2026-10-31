<?php

namespace Ernestdefoe\Kiosk\Provider;

use Ernestdefoe\Kiosk\Model\Payment;
use Ernestdefoe\Kiosk\Model\Price;
use Ernestdefoe\Kiosk\Model\Product;
use Ernestdefoe\Kiosk\Model\Purchase;

/**
 * A way to be paid. PayPal now; Stripe, BTCPay and a hosted crypto gateway
 * implement the same five things, and Kiosk's ledger stays the record.
 */
interface Provider
{
    public function key(): string;

    /** Ready to take money in the current mode. */
    public function isReady(): bool;

    /** "sandbox" / "live" (or "test" / "live"). Stored on each purchase. */
    public function mode(): string;

    /** Whether this provider can sell this price (e.g. crypto cannot renew on its own). */
    public function supports(Price $price): bool;

    /**
     * Start paying: store the provider's reference on the purchase and
     * return the URL to send the buyer to.
     */
    public function checkout(Purchase $purchase, Price $price, Product $product, string $returnUrl, string $cancelUrl): string;

    /** The buyer came back: find out from the provider what happened. */
    public function confirm(Purchase $purchase): void;

    /** Ask the provider about a purchase whose news may have been missed. */
    public function reconcile(Purchase $purchase): void;

    /** Stop a subscription renewing. True if the provider agreed. */
    public function cancel(Purchase $purchase): bool;

    /** Give a payment back in full. True if the provider agreed. */
    public function refund(Payment $payment): bool;

    /**
     * Move a subscription to another recurring price of the same product.
     * Returns a URL when the buyer must approve the change at the provider,
     * null when it is done. Throws a ValidationException when it can't.
     */
    public function switchPlan(Purchase $purchase, Price $to, string $returnUrl): ?string;
}
