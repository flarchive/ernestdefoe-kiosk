<?php

namespace Ernestdefoe\Kiosk\Service;

use Carbon\Carbon;
use Ernestdefoe\Kiosk\Model\Coupon;
use Ernestdefoe\Kiosk\Model\Product;
use Ernestdefoe\Kiosk\Model\Purchase;
use Ernestdefoe\Kiosk\Notification\PurchaseBlueprint;
use Flarum\Foundation\ValidationException;
use Flarum\Locale\TranslatorInterface;
use Flarum\Notification\NotificationSyncer;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Contracts\Filesystem\Factory as Files;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Log\LoggerInterface;

/**
 * Orders paid by bank transfer, from checkout to paid or cancelled.
 *
 * awaiting ──Mark paid──▶ pending ──Fulfilment::activate──▶ active
 *     └──Cancel / lapsed──▶ expired (stock and coupon given back)
 *
 * 🚨 Every step claims the row with its status in the WHERE clause, so two
 * people pressing Mark paid (or Mark paid racing the expiry) can't both win:
 * the order is paid for and opened exactly once. Opening it is the same
 * recordPayment + activate every provider's payment goes through.
 */
class Transfers
{
    public const DISK = 'ernestdefoe-kiosk-proofs';

    /** Orders one member may have waiting for a transfer at once (each holds stock). */
    public const MAX_WAITING = 3;

    public const MAX_PROOF_BYTES = 5 * 1024 * 1024;

    /** What a proof may be, by the type its bytes say (never the name or the browser's word). */
    private const PROOF_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];

    public function __construct(
        private Fulfilment $fulfilment,
        private SettingsRepositoryInterface $settings,
        private ConnectionInterface $db,
        private NotificationSyncer $notifications,
        private TranslatorInterface $translator,
        private Files $files,
        private ImageManager $images,
        private LoggerInterface $log,
    ) {
    }

    public function expiryDays(): int
    {
        return max(0, min(90, (int) $this->settings->get('ernestdefoe-kiosk.bank_expiry_days')));
    }

    /**
     * What the buyer writes on the transfer so it can be matched: the admin's
     * format with {number} (the order number) and {id}. Only characters every
     * bank accepts; the order number is added when the format has neither.
     */
    public function reference(Purchase $purchase): string
    {
        $format = trim((string) $this->settings->get('ernestdefoe-kiosk.bank_reference')) ?: '{number}';
        if (! str_contains($format, '{number}') && ! str_contains($format, '{id}')) {
            $format .= ' {number}';
        }

        $reference = strtr($format, ['{number}' => $purchase->number, '{id}' => (string) $purchase->id]);

        return mb_substr(trim((string) preg_replace('~[^A-Za-z0-9 ./_-]+~', '', $reference)), 0, 35);
    }

    /** Checkout chose a bank transfer: hold the stock, give the order its reference, and tell the buyer how to pay. */
    public function await(Purchase $purchase): void
    {
        if (Purchase::query()->where('status', Purchase::AWAITING)
            ->where(fn ($q) => $q->where('gifted_by', $purchase->payerId())->orWhere(fn ($own) => $own->whereNull('gifted_by')->where('user_id', $purchase->payerId())))
            ->count() >= self::MAX_WAITING) {
            throw $this->error('forum.errors.too_many_waiting');
        }

        // 🚨 One statement: two buyers can't both take the last one in stock.
        $product = $purchase->product_id ? Product::query()->find($purchase->product_id) : null;
        if ($product && $product->stock !== null) {
            $grammar = $this->db->getQueryGrammar();
            $held = Product::query()->whereKey($product->id)
                ->whereRaw($grammar->wrap('sold_count').' + '.$grammar->wrap('reserved_count').' < '.$grammar->wrap('stock'))
                ->increment('reserved_count');
            if (! $held) {
                throw $this->error('forum.errors.sold_out');
            }
            $purchase->stock_held = true;
        }

        $days = $this->expiryDays();
        $purchase->status = Purchase::AWAITING;
        $purchase->provider_ref = $this->reference($purchase);
        $purchase->expires_at = $days > 0 ? Carbon::now()->addDays($days) : null;
        $purchase->save();

        $this->tell($purchase, 'awaiting');
    }

    /**
     * The money arrived: open the order, once. False when it was already
     * paid, cancelled or lapsed (nothing happens then).
     */
    public function markPaid(Purchase $purchase, User $by): bool
    {
        // Claim it: back to pending, where activate() takes it from.
        $won = Purchase::query()->whereKey($purchase->id)->where('status', Purchase::AWAITING)
            ->update(['status' => Purchase::PENDING, 'note' => mb_substr('Transfer confirmed by '.$by->username, 0, 191), 'updated_at' => Carbon::now()]);
        if (! $won) {
            return false;
        }

        $purchase->refresh();
        // Activation counts it as sold: the hold it had becomes that sale.
        $this->release($purchase);

        $this->fulfilment->recordPayment($purchase, 'bank:'.$purchase->number, $purchase->amount_cents, $purchase->currency);
        $now = Carbon::now();
        if (! $this->fulfilment->activate($purchase, $now, $purchase->periodEndFrom($now))) {
            $this->log->error('[kiosk] '.$purchase->number.' was marked paid but did not open; open it with Grant if it should.');
        }
        $this->dropProof($purchase);

        return true;
    }

    /**
     * No money is coming: the buyer, the admin or the seller called it off,
     * or it lapsed. Its stock and coupon use go back. False if it wasn't waiting.
     */
    public function cancel(Purchase $purchase, bool $lapsed = false): bool
    {
        if (! Purchase::query()->whereKey($purchase->id)->where('status', Purchase::AWAITING)->update(['status' => Purchase::EXPIRED, 'updated_at' => Carbon::now()])) {
            return false;
        }

        $purchase->refresh();
        $this->release($purchase);
        Coupon::release($purchase->coupon_code);
        $this->dropProof($purchase);
        $this->tell($purchase, $lapsed ? 'transfer_expired' : 'transfer_canceled');

        return true;
    }

    /** @return int orders past their time that were cancelled */
    public function expireDue(int $limit = 200): int
    {
        $n = 0;
        foreach (Purchase::query()->where('status', Purchase::AWAITING)->whereNotNull('expires_at')
            ->where('expires_at', '<', Carbon::now())->orderBy('id')->limit($limit)->get() as $purchase) {
            $n += (int) $this->cancel($purchase, true);
        }

        return $n;
    }

    /** The buyer's proof of payment: a picture or PDF of the transfer, and/or a note. */
    public function attachProof(Purchase $purchase, ?UploadedFileInterface $file, string $note): void
    {
        if ($purchase->status !== Purchase::AWAITING) {
            throw $this->error('forum.transfer.errors.not_waiting');
        }

        $note = mb_substr(trim($note), 0, 500);
        if (! $file && $note === '') {
            throw $this->error('forum.transfer.errors.proof_empty', 'proof');
        }

        if ($file) {
            [$bytes, $type] = $this->sniff($file);
            $path = Str::random(40).'.'.self::PROOF_TYPES[$type];
            $this->disk()->put($path, $bytes);
            $this->dropProof($purchase);
            $purchase->proof_path = $path;
            $purchase->proof_type = $type;
        }

        $purchase->proof_note = $note !== '' ? $note : null;
        $purchase->proof_at = Carbon::now();
        $purchase->save();
    }

    /** @return array{0: string, 1: string}|null the proof's bytes and type */
    public function proof(Purchase $purchase): ?array
    {
        if (! $purchase->proof_path || ! $this->disk()->exists($purchase->proof_path)) {
            return null;
        }

        return [(string) $this->disk()->get($purchase->proof_path), (string) $purchase->proof_type];
    }

    /**
     * Read what was sent and decide what it is from its bytes. Pictures are
     * re-encoded (anything riding inside is dropped); a PDF must be one.
     *
     * @return array{0: string, 1: string}
     */
    private function sniff(UploadedFileInterface $file): array
    {
        if ($file->getError() !== UPLOAD_ERR_OK || (int) $file->getSize() > self::MAX_PROOF_BYTES) {
            throw $this->error('forum.transfer.errors.proof_file', 'proof');
        }
        $bytes = (string) $file->getStream();
        $type = strlen($bytes) > 0 && strlen($bytes) <= self::MAX_PROOF_BYTES ? (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) : false;

        if ($type === 'application/pdf' && str_starts_with($bytes, '%PDF-')) {
            return [$bytes, $type];
        }

        if (! is_string($type) || ! isset(self::PROOF_TYPES[$type]) || $type === 'application/pdf' || ! @getimagesizefromstring($bytes)) {
            throw $this->error('forum.transfer.errors.proof_file', 'proof');
        }

        try {
            return [(string) $this->images->read($bytes)->scaleDown(2400, 2400)->encodeByExtension(self::PROOF_TYPES[$type], quality: 85), $type];
        } catch (\Throwable) {
            // Headers of a picture, but the image library can't read it: refused like any other non-picture.
            throw $this->error('forum.transfer.errors.proof_file', 'proof');
        }
    }

    private function release(Purchase $purchase): void
    {
        if (! $purchase->stock_held || ! $purchase->product_id) {
            return;
        }
        Purchase::query()->whereKey($purchase->id)->update(['stock_held' => false]);
        $purchase->stock_held = false;
        // 🚨 Unsigned: never below zero (MySQL refuses it outright).
        Product::query()->whereKey($purchase->product_id)->where('reserved_count', '>', 0)->decrement('reserved_count');
    }

    private function dropProof(Purchase $purchase): void
    {
        if ($purchase->proof_path) {
            $this->disk()->delete($purchase->proof_path);
            Purchase::query()->whereKey($purchase->id)->update(['proof_path' => null, 'proof_type' => null]);
            $purchase->proof_path = null;
            $purchase->proof_type = null;
        }
    }

    /** Tell whoever pays (the giver of a gift, else the buyer). */
    private function tell(Purchase $purchase, string $kind): void
    {
        if ($payer = User::query()->find($purchase->payerId())) {
            $this->notifications->sync(new PurchaseBlueprint($purchase, $kind), [$payer]);
        }
    }

    private function disk(): Filesystem
    {
        return $this->files->disk(self::DISK);
    }

    private function error(string $key, string $field = 'checkout'): ValidationException
    {
        return new ValidationException([$field => $this->translator->trans('ernestdefoe-kiosk.'.$key)]);
    }
}
