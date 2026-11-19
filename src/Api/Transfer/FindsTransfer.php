<?php

namespace Ernestdefoe\Kiosk\Api\Transfer;

use Ernestdefoe\Kiosk\Api\Concerns;
use Ernestdefoe\Kiosk\Model\Purchase;
use Ernestdefoe\Kiosk\Provider\Bank;
use Flarum\Foundation\ValidationException;
use Flarum\User\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The order a bank-transfer route names, and who may act on it.
 *
 * 🚨 Anyone not allowed gets "not found", the same as a wrong number. Marking
 * paid is never the payer's: they could otherwise open what they bought for free.
 */
trait FindsTransfer
{
    use Concerns;

    /** @return array{0: Purchase, 1: User} */
    protected function transfer(ServerRequestInterface $request): array
    {
        $actor = $this->actor($request);
        $actor->assertRegistered();

        $purchase = Purchase::query()->where('provider', Bank::KEY)->where('number', $this->param($request, 'number'))->first()
            ?? throw new ModelNotFoundException();

        return [$purchase, $actor];
    }

    /** The admin, or the member whose listing it is (the money is theirs). */
    protected function isStaff(Purchase $purchase, User $actor): bool
    {
        return $actor->isAdmin() || ($purchase->seller_id && (int) $purchase->seller_id === (int) $actor->id);
    }

    protected function isPayer(Purchase $purchase, User $actor): bool
    {
        return $purchase->payerId() === (int) $actor->id;
    }

    protected function allow(bool $allowed): void
    {
        if (! $allowed) {
            throw new ModelNotFoundException();
        }
    }

    protected function invalid(string $reason, string $field = 'purchase'): ValidationException
    {
        return new ValidationException([$field => $this->translator->trans('ernestdefoe-kiosk.forum.transfer.errors.'.$reason)]);
    }

    /** The payer sees their order again; the admin's and seller's pages reload their own lists. */
    protected function reply(Purchase $purchase, User $actor): \Psr\Http\Message\ResponseInterface
    {
        if (! $this->isPayer($purchase, $actor)) {
            return new \Laminas\Diactoros\Response\EmptyResponse(204);
        }

        $purchase->refresh()->load(['entitlements', 'payments']);

        return new \Laminas\Diactoros\Response\JsonResponse(['data' => $this->presenter->purchases([$purchase], false, $actor)[0]]);
    }
}
