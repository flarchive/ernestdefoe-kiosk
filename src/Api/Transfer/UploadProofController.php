<?php

namespace Ernestdefoe\Kiosk\Api\Transfer;

use Ernestdefoe\Kiosk\Service\Presenter;
use Ernestdefoe\Kiosk\Service\Transfers;
use Flarum\Locale\TranslatorInterface;
use Illuminate\Contracts\Cache\Repository as Cache;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** POST /kiosk/transfers/{number}/proof — the payer's proof: a file (`proof`) and/or a `note`. */
class UploadProofController implements RequestHandlerInterface
{
    use FindsTransfer;

    /** Proof uploads one member may make in an hour. */
    private const PER_HOUR = 10;

    public function __construct(private Transfers $transfers, private Presenter $presenter, private TranslatorInterface $translator, private Cache $cache)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        [$purchase, $actor] = $this->transfer($request);
        $this->allow($this->isPayer($purchase, $actor));

        $key = 'kiosk.proofs.'.$actor->id;
        $used = (int) $this->cache->get($key, 0);
        if ($used >= self::PER_HOUR) {
            throw $this->invalid('too_many_uploads', 'proof');
        }
        $this->cache->put($key, $used + 1, 3600);

        $file = $request->getUploadedFiles()['proof'] ?? null;
        $this->transfers->attachProof(
            $purchase,
            $file instanceof UploadedFileInterface && $file->getError() !== UPLOAD_ERR_NO_FILE ? $file : null,
            (string) (((array) $request->getParsedBody())['note'] ?? '')
        );

        return $this->reply($purchase, $actor);
    }
}
