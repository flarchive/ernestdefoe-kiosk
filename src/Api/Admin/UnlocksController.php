<?php

namespace Ernestdefoe\Kiosk\Api\Admin;

use Ernestdefoe\Kiosk\Api\Concerns;
use Ernestdefoe\Kiosk\Service\Presenter;
use Ernestdefoe\Kiosk\Unlock\Registry;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /kiosk/admin/unlocks — the kinds of thing this forum can sell.
 * GET /kiosk/admin/unlocks?type=tag&q=lou — items of one kind, for the picker.
 */
class UnlocksController implements RequestHandlerInterface
{
    use Concerns;

    public function __construct(private Registry $types, private Presenter $presenter)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->admin($request);
        $q = $this->query($request);
        $type = isset($q['type']) ? $this->types->get((string) $q['type']) : null;

        if ($type) {
            return new JsonResponse(['data' => $type->search($actor, mb_substr(trim((string) ($q['q'] ?? '')), 0, 100), 20)]);
        }

        return new JsonResponse(['data' => $this->presenter->unlockTypes()]);
    }
}
