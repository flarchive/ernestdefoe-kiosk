<?php

namespace Ernestdefoe\Kiosk\Notification;

use Ernestdefoe\Kiosk\Model\Money;
use Ernestdefoe\Kiosk\Model\Purchase;
use Flarum\Database\AbstractModel;
use Flarum\Locale\TranslatorInterface;
use Flarum\Notification\AlertableInterface;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\Notification\MailableInterface;
use Flarum\User\User;

/**
 * Everything Kiosk tells a buyer, one type: a receipt, a failed renewal, a
 * renewal coming up, access ended. `kind` picks the words; the period end is
 * in the data so each renewal's receipt is its own notification rather than
 * the same one synced again.
 */
class PurchaseBlueprint implements BlueprintInterface, AlertableInterface, MailableInterface
{
    public const KINDS = ['receipt', 'payment_failed', 'reminder', 'ended', 'gift', 'seat', 'awaiting', 'transfer_canceled', 'transfer_expired'];

    public function __construct(public Purchase $purchase, public string $kind)
    {
    }

    public function getFromUser(): ?User
    {
        return null;
    }

    public function getSubject(): ?AbstractModel
    {
        return $this->purchase;
    }

    public function getData(): array
    {
        return [
            'kind' => $this->kind,
            'product' => $this->purchase->product_name,
            'until' => $this->purchase->period_end?->toIso8601String(),
        ] + ($this->kind === 'awaiting' ? ['number' => $this->purchase->number] : []);
    }

    public static function getType(): string
    {
        return 'kioskPurchase';
    }

    public static function getSubjectModel(): string
    {
        return Purchase::class;
    }

    public function getEmailViews(): array
    {
        return ['text' => 'ernestdefoe-kiosk::emails.plain.notification', 'html' => 'ernestdefoe-kiosk::emails.html.notification'];
    }

    public function getEmailSubject(TranslatorInterface $translator): string
    {
        return $translator->trans('ernestdefoe-kiosk.email.'.$this->kind.'.subject', ['{product}' => $this->purchase->product_name]);
    }

    /**
     * The words of the email: a translation and its parameters, and for an
     * order waiting for a bank transfer, the account to pay into as a block of
     * its own (lines kept, never parsed as Markdown).
     *
     * @return array{key: string, params: array<string, string>, block?: array{details: string, instructions: string}}
     */
    public function email(): array
    {
        $p = $this->purchase;
        $mail = [
            'key' => 'ernestdefoe-kiosk.email.'.$this->kind.'.body',
            'params' => [
                '{product}' => $p->product_name,
                '{number}' => $p->number,
                '{amount}' => Money::toDecimal($p->amount_cents, $p->currency).' '.$p->currency,
                '{until}' => $p->period_end ? $p->period_end->toFormattedDateString() : '',
            ],
        ];

        if ($this->kind === 'awaiting') {
            [$mail['params'], $mail['block']] = $this->howToPay($mail['params']);
        }

        return $mail;
    }

    /**
     * The account to pay into, for the confirmation of an order waiting for a
     * bank transfer. Read when the email is written: never stored with the
     * notification.
     *
     * @param array<string, string> $params
     * @return array{0: array<string, string>, 1: array{details: string, instructions: string}}
     */
    private function howToPay(array $params): array
    {
        // resolve(), not injection: blueprints are built with `new` and are
        // serialized into the mail queue. Reading the account here, as the
        // email is written, keeps a seller's sealed details out of the queue.
        $p = $this->purchase;
        $bank = resolve(\Ernestdefoe\Kiosk\Provider\Providers::class)->forPurchase($p);

        return [$params + [
            '{reference}' => (string) $p->provider_ref,
            '{deadline}' => $p->expires_at ? (string) resolve(TranslatorInterface::class)->trans('ernestdefoe-kiosk.forum.transfer.expires', ['{date}' => $p->expires_at->toFormattedDateString()]) : '',
            '{order_url}' => resolve(\Flarum\Http\UrlGenerator::class)->to('forum')->route('kiosk.return', ['number' => $p->number]),
        ], $bank instanceof \Ernestdefoe\Kiosk\Provider\Bank ? $bank->payTo() : ['details' => '', 'instructions' => '']];
    }
}
