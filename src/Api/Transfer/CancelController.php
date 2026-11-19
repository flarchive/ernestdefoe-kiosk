<?php

namespace Ernestdefoe\Kiosk\Api\Transfer;

use Ernestdefoe\Kiosk\Service\Presenter;
use Ernestdefoe\Kiosk\Service\Transfers;
use Flarum\Locale\TranslatorInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** POST /kiosk/transfers/{number}/cancel — no money is coming (the admin, the seller, or the payer). */
class CancelController implements RequestHandlerInterface
{
    use FindsTransfer;

    public function __construct(private Transfers $transfers, private Presenter $presenter, private TranslatorInterface $translator)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        [$purchase, $actor] = $this->transfer($request);
        $this->allow($this->isStaff($purchase, $actor) || $this->isPayer($purchase, $actor));

        if (! $this->transfers->cancel($purchase)) {
            throw $this->invalid('not_waiting');
        }

        return $this->reply($purchase, $actor);
    }
}
