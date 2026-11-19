<?php

namespace Ernestdefoe\Kiosk\Api;

use Ernestdefoe\Kiosk\Service\Checkout;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** POST /kiosk/checkout {priceId, provider} → where to send the buyer. */
class CheckoutController implements RequestHandlerInterface
{
    use Concerns;

    public function __construct(private Checkout $checkout)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->body($request);

        return new JsonResponse(['data' => $this->checkout->start(
            $this->actor($request),
            (int) ($body['priceId'] ?? 0),
            (string) ($body['provider'] ?? ''),
            array_intersect_key($body, array_flip(['amount', 'context', 'coupon', 'giftTo'])),
        )]);
    }
}
