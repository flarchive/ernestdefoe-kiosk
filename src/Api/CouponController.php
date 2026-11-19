<?php

namespace Ernestdefoe\Kiosk\Api;

use Ernestdefoe\Kiosk\Model\Price;
use Ernestdefoe\Kiosk\Service\Checkout;
use Flarum\Foundation\ValidationException;
use Flarum\Locale\TranslatorInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /kiosk/coupons/check {code, priceId} → what the price costs with it,
 * so the card can show the new total before the buyer pays. Checkout checks
 * the code again; this answer only previews.
 */
class CouponController implements RequestHandlerInterface
{
    use Concerns;

    public function __construct(private Checkout $checkout, private TranslatorInterface $translator)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->actor($request);
        $actor->assertRegistered();
        $body = $this->body($request);

        $price = Price::query()->whereNull('archived_at')->find((int) ($body['priceId'] ?? 0));
        $amount = $price ? $this->checkout->coupon((string) ($body['code'] ?? ''), $actor)?->apply($price) : null;

        if ($amount === null || ($amount === 0 && $price?->isRecurring())) {
            throw new ValidationException(['coupon' => $this->translator->trans('ernestdefoe-kiosk.forum.errors.coupon')]);
        }

        return new JsonResponse(['data' => ['amountCents' => $amount, 'currency' => strtoupper($price->currency)]]);
    }
}
