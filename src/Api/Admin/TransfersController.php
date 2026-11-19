<?php

namespace Ernestdefoe\Kiosk\Api\Admin;

use Ernestdefoe\Kiosk\Api\Concerns;
use Ernestdefoe\Kiosk\Model\Purchase;
use Ernestdefoe\Kiosk\Service\Presenter;
use Flarum\User\User;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /kiosk/admin/transfers — the admin's queue: the oldest 100 orders waiting
 * for a bank transfer, with who is paid when it's a member's listing.
 * Members, sellers and entitlements in a fixed few queries, whatever the count.
 */
class TransfersController implements RequestHandlerInterface
{
    use Concerns;

    private const PAGE = 100;

    public function __construct(private Presenter $presenter)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->admin($request);

        $rows = Purchase::query()->where('status', Purchase::AWAITING)->with(['entitlements', 'payments', 'user'])
            ->orderBy('id')->limit(self::PAGE)->get();
        $sellers = User::query()->whereIn('id', $rows->pluck('seller_id')->filter()->unique())->get()->keyBy('id');

        return new JsonResponse(['data' => array_map(
            fn (array $row, Purchase $p) => $row + ['seller' => ($s = $sellers->get($p->seller_id)) ? ['username' => $s->username, 'displayName' => $s->display_name] : null],
            $this->presenter->purchases($rows, true),
            $rows->all(),
        )]);
    }
}
