<?php

namespace Ernestdefoe\Kiosk\Api\Transfer;

use Ernestdefoe\Kiosk\Service\Presenter;
use Ernestdefoe\Kiosk\Service\Transfers;
use Flarum\Locale\TranslatorInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** POST /kiosk/transfers/{number}/paid — the money arrived (the admin, or the order's seller). Twice opens it once. */
class MarkPaidController implements RequestHandlerInterface
{
    use FindsTransfer;

    public function __construct(private Transfers $transfers, private Presenter $presenter, private TranslatorInterface $translator)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        [$purchase, $actor] = $this->transfer($request);
        $this->allow($this->isStaff($purchase, $actor));

        if (! $this->transfers->markPaid($purchase, $actor)) {
            throw $this->invalid('not_waiting');
        }

        return $this->reply($purchase, $actor);
    }
}
