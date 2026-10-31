<?php

namespace Ernestdefoe\Kiosk\Service;

use Ernestdefoe\Kiosk\Model\Payment;
use Ernestdefoe\Kiosk\Model\Purchase;
use Ernestdefoe\Kiosk\Provider\Crypto;
use Ernestdefoe\Kiosk\Provider\Providers;

/** Give back the latest payment of a purchase and end it: for the admin, and for a seller on their own sales. */
class Refunds
{
    public function __construct(private Providers $providers, private Fulfilment $fulfilment)
    {
    }

    /** @return string|null why it couldn't (a translation key's last part), or null when done */
    public function refund(Purchase $purchase): ?string
    {
        $provider = $purchase->provider === Purchase::GRANT ? null : $this->providers->forPurchase($purchase);
        // The latest payment: the period they are in now.
        $payment = $purchase->payments()->where('status', Payment::COMPLETED)->orderByDesc('id')->first();

        if (! $provider || ! $payment) {
            return 'nothing_to_refund';
        }

        // Paid to a member's PayPal email: the money is in their PayPal, so they
        // refund it there and this records it (and ends the buyer's access).
        $byEmail = $provider instanceof \Ernestdefoe\Kiosk\Provider\PayPal && $provider->paysByEmail();

        if (! $byEmail && ! $provider->refund($payment)) {
            // Crypto can only be sent back from the gateway itself.
            return $provider instanceof Crypto ? 'refund_crypto' : 'refund_failed';
        }

        $payment->status = Payment::REFUNDED;
        $payment->refunded_cents = $payment->amount_cents;
        $payment->save();
        if ($purchase->isRecurring()) {
            $provider->cancel($purchase);
        }
        $this->fulfilment->end($purchase, Purchase::REFUNDED);

        return null;
    }
}
