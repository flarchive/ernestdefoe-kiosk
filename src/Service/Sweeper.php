<?php

namespace Ernestdefoe\Kiosk\Service;

use Carbon\Carbon;
use Ernestdefoe\Kiosk\Model\Price;
use Ernestdefoe\Kiosk\Model\Purchase;
use Ernestdefoe\Kiosk\Provider\Providers;
use Psr\Log\LoggerInterface;

/**
 * The scheduled housekeeping. Access itself never waits for this — Access
 * stops honouring an entitlement the second it ends — so a forum without a
 * scheduler still locks lapsed members out on time. This tidies up behind
 * it: removes memberships, expires abandoned checkouts, reminds, and asks
 * PayPal about anything a missed webhook may have left behind.
 */
class Sweeper
{
    /** A checkout nobody finished in this long is abandoned. */
    private const ABANDONED_HOURS = 3;

    /** Provider calls per run, so a backlog can never hammer PayPal. */
    private const RECONCILE_CAP = 25;

    public function __construct(
        private Fulfilment $fulfilment,
        private Providers $providers,
        private LoggerInterface $log,
    ) {
    }

    /** @return array<string, int> */
    public function run(): array
    {
        $now = Carbon::now();
        $out = ['closed' => 0, 'expired' => 0, 'ended' => 0, 'reminded' => 0, 'reconciled' => 0];

        $out['reconciled'] = $this->reconcile($now);

        $out['closed'] = $this->fulfilment->closeDue();

        // 🚨 A crypto payment can take hours to confirm: those wait two days.
        $abandoned = Purchase::query()
            ->where('status', Purchase::PENDING)
            ->where('created_at', '<', $now->copy()->subHours(self::ABANDONED_HOURS))
            ->where(fn ($q) => $q->whereNotIn('provider', ['btcpay', 'nowpayments'])->orWhere('created_at', '<', $now->copy()->subHours(48)))
            ->limit(500)->get(['id', 'coupon_code']);
        $out['expired'] = 0;
        foreach ($abandoned as $purchase) {
            // Only the row that really expired gives its coupon use back.
            if (Purchase::query()->whereKey($purchase->id)->where('status', Purchase::PENDING)->update(['status' => Purchase::EXPIRED, 'updated_at' => $now])) {
                $out['expired']++;
                \Ernestdefoe\Kiosk\Model\Coupon::release($purchase->coupon_code);
            }
        }

        // Past the period and the grace with no renewal: it is over.
        $graceEnd = $now->copy()->subDays($this->fulfilment->graceDays());
        foreach (Purchase::query()
            ->whereIn('status', [Purchase::ACTIVE, Purchase::CANCELED])
            ->whereNotNull('period_end')
            ->where('period_end', '<', $graceEnd)
            ->limit(200)->get() as $purchase) {
            $purchase->status = Purchase::ENDED;
            $purchase->ended_at = $now;
            $purchase->save();
            $this->fulfilment->notify($purchase, 'ended');
            $out['ended']++;
        }

        // A week's notice before a yearly renewal: nobody likes a surprise
        // annual charge, and it is what card networks expect.
        foreach (Purchase::query()
            ->where('status', Purchase::ACTIVE)
            ->where('kind', Price::RECURRING)
            ->where('interval', 'year')
            ->whereNull('reminded_at')
            ->whereBetween('period_end', [$now, $now->copy()->addDays(7)])
            ->limit(200)->get() as $purchase) {
            $purchase->reminded_at = $now;
            $purchase->save();
            $this->fulfilment->notify($purchase, 'reminder');
            $out['reminded']++;
        }

        return $out;
    }

    private function reconcile(Carbon $now): int
    {
        $candidates = Purchase::query()
            ->where('provider', '!=', Purchase::GRANT)
            ->whereNotNull('provider_ref')
            ->where(fn ($q) => $q
                // Paid for, maybe, but the news never came.
                ->where(fn ($q) => $q->where('status', Purchase::PENDING)
                    ->where('created_at', '<', $now->copy()->subMinutes(10))
                    ->where('created_at', '>', $now->copy()->subHours(self::ABANDONED_HOURS)))
                // Due to have renewed, and we have not heard.
                ->orWhere(fn ($q) => $q->where('status', Purchase::ACTIVE)
                    ->where('kind', Price::RECURRING)
                    ->where('period_end', '<', $now)))
            ->orderBy('updated_at')
            ->limit(self::RECONCILE_CAP)
            ->get();

        $n = 0;
        foreach ($candidates as $purchase) {
            try {
                $this->providers->forPurchase($purchase)?->reconcile($purchase);
                $n++;
            } catch (\Throwable $e) {
                $this->log->warning('[kiosk] reconcile '.$purchase->number.' failed: '.$e->getMessage());
            }
            // Rotate through the backlog rather than asking about the same rows every run.
            Purchase::query()->whereKey($purchase->id)->update(['updated_at' => $now]);
        }

        return $n;
    }
}
