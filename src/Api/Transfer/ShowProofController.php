<?php

namespace Ernestdefoe\Kiosk\Api\Transfer;

use Ernestdefoe\Kiosk\Service\Presenter;
use Ernestdefoe\Kiosk\Service\Transfers;
use Flarum\Locale\TranslatorInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\StreamFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** GET /kiosk/transfers/{number}/proof — the proof file, for the payer, the seller and admins. */
class ShowProofController implements RequestHandlerInterface
{
    use FindsTransfer;

    public function __construct(private Transfers $transfers, private Presenter $presenter, private TranslatorInterface $translator)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        [$purchase, $actor] = $this->transfer($request);
        $this->allow($this->isStaff($purchase, $actor) || $this->isPayer($purchase, $actor));

        [$bytes, $type] = $this->transfers->proof($purchase) ?? throw new ModelNotFoundException();
        $ext = $type === 'application/pdf' ? 'pdf' : explode('/', $type)[1];

        return new Response((new StreamFactory())->createStream($bytes), 200, [
            'Content-Type' => $type,
            'Content-Disposition' => 'inline; filename="'.$purchase->number.'-proof.'.$ext.'"',
            // A file someone uploaded: never sniffed, never run, never cached.
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox",
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
