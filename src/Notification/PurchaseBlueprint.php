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
    public const KINDS = ['receipt', 'payment_failed', 'reminder', 'ended', 'gift', 'seat'];

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
        ];
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

    /** @return array{key: string, params: array<string, string>} */
    public function email(): array
    {
        $p = $this->purchase;

        return [
            'key' => 'ernestdefoe-kiosk.email.'.$this->kind.'.body',
            'params' => [
                '{product}' => $p->product_name,
                '{number}' => $p->number,
                '{amount}' => Money::toDecimal($p->amount_cents, $p->currency).' '.$p->currency,
                '{until}' => $p->period_end ? $p->period_end->toFormattedDateString() : '',
            ],
        ];
    }
}
