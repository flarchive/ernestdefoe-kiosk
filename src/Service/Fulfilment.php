<?php

namespace Ernestdefoe\Kiosk\Service;

use Carbon\Carbon;
use Ernestdefoe\Kiosk\Event;
use Ernestdefoe\Kiosk\Model\Entitlement;
use Ernestdefoe\Kiosk\Model\Payment;
use Ernestdefoe\Kiosk\Model\Price;
use Ernestdefoe\Kiosk\Model\Product;
use Ernestdefoe\Kiosk\Model\Purchase;
use Ernestdefoe\Kiosk\Notification\PurchaseBlueprint;
use Ernestdefoe\Kiosk\Unlock\Registry;
use Flarum\Notification\NotificationSyncer;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Psr\Log\LoggerInterface;

/**
 * Turns money into access and back. Every provider, the admin's Grant button
 * and the scheduler come through here, so there is one implementation of
 * "this purchase now gives access until X" and one of "it no longer does".
 */
class Fulfilment
{
    public function __construct(
        private ConnectionInterface $db,
        private Registry $types,
        private Access $access,
        private SettingsRepositoryInterface $settings,
        private Dispatcher $events,
        private NotificationSyncer $notifications,
        private LoggerInterface $log,
    ) {
    }

    public function graceDays(): int
    {
        return max(0, min(30, (int) $this->settings->get('ernestdefoe-kiosk.grace_days', 3)));
    }

    /**
     * The purchase was paid for (or given): give access for the period.
     *
     * 🚨 Exactly once. The status is in the WHERE clause, so the buyer coming
     * back from PayPal and the webhook arriving at the same moment cannot both
     * activate it: the row count says who won, and the loser does nothing.
     *
     * @return bool whether this call was the one that activated it
     */
    public function activate(Purchase $purchase, Carbon $periodStart, ?Carbon $periodEnd): bool
    {
        if ($this->wrongMode($purchase)) {
            return false;
        }

        // 🚨 The status change and the access it pays for commit together: a
        // crash in between must not leave an "active" purchase that opens nothing.
        $won = $this->db->transaction(fn () => $this->activateInTransaction($purchase, $periodStart, $periodEnd));

        if ($won) {
            $this->events->dispatch(new Event\PurchaseActivated($purchase));
            $this->notify($purchase, $purchase->gifted_by ? 'gift' : 'receipt');

            // The giver gets the receipt; the recipient was told it's a gift.
            if ($purchase->gifted_by && ($giver = User::query()->find($purchase->gifted_by))) {
                $this->notifications->sync(new PurchaseBlueprint($purchase, 'receipt'), [$giver]);
            }
        }

        return $won;
    }

    private function activateInTransaction(Purchase $purchase, Carbon $periodStart, ?Carbon $periodEnd): bool
    {
        $won = Purchase::query()->whereKey($purchase->id)->where('status', Purchase::PENDING)->update([
            'status' => Purchase::ACTIVE,
            'paid_at' => Carbon::now(),
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'updated_at' => Carbon::now(),
        ]);

        if (! $won) {
            return false;
        }

        $purchase->refresh();

        if ($purchase->product_id) {
            Product::query()->whereKey($purchase->product_id)->increment('sold_count');
        }

        $this->open($purchase);

        return true;
    }

    /** A renewal was paid: move the period on. Safe to call again with the same end. */
    public function renew(Purchase $purchase, Carbon $periodEnd): void
    {
        if ($purchase->period_end && $periodEnd->lessThanOrEqualTo($purchase->period_end)) {
            return;
        }
        // 🚨 An abandoned checkout (its coupon use given back) or a refund
        // opens nothing here: a late payment goes through revive + activate.
        if (in_array($purchase->status, [Purchase::EXPIRED, Purchase::REFUNDED], true)) {
            return;
        }
        // A test subscription goes on renewing at the provider after the
        // forum goes live: it doesn't keep access going.
        if ($this->wrongMode($purchase)) {
            return;
        }

        $purchase->period_end = $periodEnd;
        $purchase->reminded_at = null;
        if ($purchase->status === Purchase::ENDED || $purchase->status === Purchase::PENDING) {
            $purchase->status = Purchase::ACTIVE;
        }
        $purchase->save();

        // A renewal after the grace ran out re-opens what was closed.
        $this->open($purchase);

        $this->events->dispatch(new Event\PurchaseRenewed($purchase));
    }

    /**
     * A checkout that expired (abandoned) is paid after all: make it pending
     * again so it opens through activate() and its checks. Its coupon use
     * was given back, so it must be taken again; if it can't be, it isn't
     * revived and the admin sees why in the log.
     */
    public function revive(Purchase $purchase): bool
    {
        if ($purchase->status !== Purchase::EXPIRED || $purchase->cancel_at_period_end) {
            return false;
        }
        $coupon = $purchase->coupon_code ? \Ernestdefoe\Kiosk\Model\Coupon::query()->where('code', $purchase->coupon_code)->first() : null;
        if ($purchase->coupon_code && ! $coupon?->reserve()) {
            $this->log->error('[kiosk] '.$purchase->number.' was paid after it expired, but its coupon '.$purchase->coupon_code.' has no uses left. Grant it by hand if it should open.');

            return false;
        }
        if (! Purchase::query()->whereKey($purchase->id)->where('status', Purchase::EXPIRED)->update(['status' => Purchase::PENDING])) {
            \Ernestdefoe\Kiosk\Model\Coupon::release($purchase->coupon_code);

            return false;
        }
        $purchase->refresh();

        return true;
    }

    /**
     * 🚨 A test (sandbox) purchase once the provider is switched to live, or
     * the other way round: its money isn't the money the forum now takes.
     */
    private function wrongMode(Purchase $purchase): bool
    {
        if ($purchase->provider === Purchase::GRANT || ! $purchase->mode) {
            return false;
        }

        $provider = resolve(\Ernestdefoe\Kiosk\Provider\Providers::class)->get($purchase->provider);

        if ($provider && $provider->mode() !== $purchase->mode) {
            $this->log->warning('[kiosk] '.$purchase->number.' was made in '.$purchase->mode.' mode; '.$purchase->provider.' is in '.$provider->mode().' mode now, so it opens nothing.');

            return true;
        }

        return false;
    }

    /** It will not renew; access runs to the end of the period already paid for. */
    public function cancelAtPeriodEnd(Purchase $purchase): void
    {
        if (! in_array($purchase->status, [Purchase::ACTIVE, Purchase::PENDING], true)) {
            return;
        }

        $expiring = $purchase->status === Purchase::PENDING;
        $purchase->status = $expiring ? Purchase::EXPIRED : Purchase::CANCELED;
        $purchase->cancel_at_period_end = true;
        $purchase->save();
        if ($expiring) {
            \Ernestdefoe\Kiosk\Model\Coupon::release($purchase->coupon_code);
        }

        // Recurring access was given grace past period_end for late renewals;
        // with no renewal coming, it ends at period_end itself.
        if ($purchase->period_end) {
            Entitlement::query()->where('purchase_id', $purchase->id)->whereNull('closed_at')->update(['ends_at' => $purchase->period_end]);
            $this->access->forget($purchase->user_id);
        }

        $this->events->dispatch(new Event\PurchaseCanceled($purchase));
    }

    /** Access stops now: a refund, a chargeback, an admin ending it. */
    public function end(Purchase $purchase, string $status = Purchase::ENDED): void
    {
        $purchase->status = $status;
        $purchase->ended_at = Carbon::now();
        if ($purchase->period_end === null || $purchase->period_end->isFuture()) {
            $purchase->period_end = Carbon::now();
        }
        $purchase->save();

        Entitlement::query()->where('purchase_id', $purchase->id)->whereNull('closed_at')->update(['ends_at' => Carbon::now()]);
        $this->closeDue(Entitlement::query()->where('purchase_id', $purchase->id)->distinct()->pluck('user_id')->map(fn ($v) => (int) $v)->all());

        $this->events->dispatch(new Event\PurchaseEnded($purchase));
        $this->notify($purchase, 'ended');
    }

    /**
     * Record money that came in. False if this provider payment was already
     * recorded — which is how a webhook delivered twice is told apart.
     */
    public function recordPayment(Purchase $purchase, string $providerPaymentId, int $amountCents, string $currency): bool
    {
        try {
            $payment = new Payment();
            $payment->purchase_id = $purchase->id;
            $payment->provider = $purchase->provider;
            $payment->provider_payment_id = $providerPaymentId;
            $payment->amount_cents = $amountCents;
            $payment->currency = strtoupper($currency);
            $payment->status = Payment::COMPLETED;
            $payment->created_at = Carbon::now();
            $payment->save();

            return true;
        } catch (QueryException $e) {
            if (Payment::query()->where('provider', $purchase->provider)->where('provider_payment_id', $providerPaymentId)->exists()) {
                return false;
            }

            throw $e;
        }
    }

    /**
     * An admin gives a product away: for $days, or forever with 0.
     * Provider "grant" and no money, so it never shows as revenue and the
     * renewal machinery never tries to charge anybody for it.
     */
    public function grant(User $user, Product $product, int $days, ?string $note = null): Purchase
    {
        $purchase = new Purchase();
        $purchase->number = Purchase::newNumber();
        $purchase->user_id = $user->id;
        $purchase->product_id = $product->id;
        $purchase->product_name = $product->name;
        $purchase->amount_cents = 0;
        $purchase->currency = strtoupper((string) ($product->prices->first()->currency ?? 'USD'));
        $purchase->kind = $days > 0 ? Price::FIXED_TERM : Price::ONE_OFF;
        $purchase->term_days = $days > 0 ? $days : null;
        // A team product granted comes with its team's seats.
        $purchase->seats = max(1, (int) ($product->prices->first()->seats ?? 1));
        $purchase->provider = Purchase::GRANT;
        $purchase->status = Purchase::PENDING;
        $purchase->note = $note !== null ? mb_substr($note, 0, 191) : null;
        $purchase->save();

        $now = Carbon::now();
        $this->activate($purchase, $now, $days > 0 ? $now->copy()->addDays($days) : null);

        return $purchase->refresh();
    }

    /**
     * Take away what has run out: close every entitlement past its end and,
     * where nothing else still covers the same item, call the unlock type's
     * revoke. Limited to one member when given, or a batch of everyone.
     *
     * @return int how many were closed
     */
    /** @param int|list<int>|null $userIds one member, several, or everyone */
    public function closeDue(int|array|null $userIds = null, int $limit = 500): int
    {
        $due = Entitlement::query()
            ->whereNull('closed_at')
            ->whereNotNull('ends_at')
            ->where('ends_at', '<=', Carbon::now())
            ->when($userIds !== null, fn ($q) => $q->whereIn('user_id', (array) $userIds))
            ->orderBy('id')
            ->limit($limit)
            ->get();
        $users = $due->isEmpty() ? collect() : User::query()->whereIn('id', $due->pluck('user_id')->unique())->get()->keyBy('id');

        $closed = 0;

        foreach ($due as $entitlement) {
            $won = Entitlement::query()->whereKey($entitlement->id)->whereNull('closed_at')->update(['closed_at' => Carbon::now()]);

            if (! $won) {
                continue;
            }

            $closed++;

            $stillHeld = Entitlement::query()->live()
                ->where('user_id', $entitlement->user_id)
                ->where('type', $entitlement->type)
                ->where('target_id', $entitlement->target_id)
                ->exists();

            if (! $stillHeld && ($user = $users->get($entitlement->user_id))) {
                try {
                    $this->types->get($entitlement->type)?->revoke($user, $entitlement->target_id);
                } catch (\Throwable $e) {
                    // One item's extension failing must not keep everyone else's access open.
                    $this->log->error('[kiosk] revoke failed for '.$entitlement->type.':'.$entitlement->target_id.': '.$e->getMessage());
                }
            }

            $this->access->forget($entitlement->user_id);
        }

        return $closed;
    }

    /**
     * Write (or extend) one entitlement per unlock for everyone the purchase
     * covers — its buyer, and anyone given one of its team seats — and call
     * each type's grant. The unlocks are read from the product when access
     * first opens and kept with the purchase after that.
     */
    public function open(Purchase $purchase): void
    {
        $existing = Entitlement::query()->where('purchase_id', $purchase->id)->get();
        $own = $existing->where('user_id', $purchase->user_id);
        $targets = $own->isNotEmpty()
            ? $own->map(fn (Entitlement $e) => ['type' => $e->type, 'target_id' => $e->target_id])->values()->all()
            : $this->db->table('kiosk_product_unlocks')->where('product_id', $purchase->product_id)->get(['type', 'target_id'])
                ->map(fn ($u) => ['type' => (string) $u->type, 'target_id' => (int) $u->target_id])->all();

        // Recurring access outlives the period by the grace days, so a renewal
        // that is a day late does not lock a paying member out.
        $endsAt = $purchase->period_end && $purchase->isRecurring() && ! $purchase->cancel_at_period_end
            ? $purchase->period_end->copy()->addDays($this->graceDays())
            : $purchase->period_end;

        $holders = [$purchase->user_id, ...$this->db->table('kiosk_seats')->where('purchase_id', $purchase->id)->pluck('user_id')->map(fn ($v) => (int) $v)->all()];

        $users = User::query()->whereIn('id', array_unique($holders))->get()->keyBy('id');
        // notify() reads the owner next; don't fetch them twice.
        if ($owner = $users->get($purchase->user_id)) {
            $purchase->setRelation('user', $owner);
        }

        foreach ($users as $userId => $user) {
            $userId = (int) $userId;

            foreach ($targets as $target) {
                $entitlement = $existing->first(fn (Entitlement $e) => $e->user_id === $userId && $e->type === $target['type'] && $e->target_id === $target['target_id']) ?? new Entitlement();
                $entitlement->purchase_id = $purchase->id;
                $entitlement->type = $target['type'];
                $entitlement->target_id = $target['target_id'];
                $entitlement->user_id = $userId;
                $entitlement->starts_at ??= $purchase->period_start ?? Carbon::now();
                $entitlement->ends_at = $endsAt;
                $entitlement->closed_at = null;
                $entitlement->save();

                try {
                    $this->types->get($target['type'])?->grant($user, $target['target_id']);
                } catch (\Throwable $e) {
                    $this->log->error('[kiosk] grant failed for '.$target['type'].':'.$target['target_id'].': '.$e->getMessage());
                }
            }

            $this->access->forget($userId);
        }
    }

    /**
     * The provider moved this subscription to another price: the purchase
     * takes the new terms from its next bill. A coupon doesn't carry over.
     */
    public function switchPrice(Purchase $purchase, Price $to): void
    {
        if ($purchase->price_id === $to->id || $to->product_id !== $purchase->product_id || ! $to->isRecurring()) {
            return;
        }

        $purchase->price_id = $to->id;
        $purchase->amount_cents = $to->amount_cents;
        $purchase->currency = strtoupper($to->currency);
        $purchase->interval = $to->interval;
        $purchase->interval_count = $to->interval_count;
        $purchase->seats = max(1, (int) $to->seats);
        $purchase->coupon_code = null;
        $purchase->save();

        // Fewer seats on the new plan: the latest seat holders lose theirs.
        $over = $this->db->table('kiosk_seats')->where('purchase_id', $purchase->id)->count() - ($purchase->seats - 1);
        $extra = $over > 0 ? $this->db->table('kiosk_seats')->where('purchase_id', $purchase->id)->orderByDesc('added_at')->limit($over)->pluck('user_id') : [];
        foreach ($extra as $userId) {
            $this->db->table('kiosk_seats')->where('purchase_id', $purchase->id)->where('user_id', $userId)->delete();
            Entitlement::query()->where('purchase_id', $purchase->id)->where('user_id', $userId)->whereNull('closed_at')->update(['ends_at' => Carbon::now()]);
            $this->closeDue((int) $userId);
        }
    }

    public function notify(Purchase $purchase, string $kind): void
    {
        if ($purchase->provider === Purchase::GRANT && ! in_array($kind, ['receipt', 'gift'], true)) {
            return;
        }

        $user = $purchase->user ?? User::query()->find($purchase->user_id);

        if ($user) {
            $this->notifications->sync(new PurchaseBlueprint($purchase, $kind), [$user]);
        }
    }
}
