<?php

namespace Ernestdefoe\Kiosk\Api;

use Flarum\Http\RequestUtil;
use Flarum\User\User;
use Illuminate\Support\Arr;
use Psr\Http\Message\ServerRequestInterface;

/** The lookups every Kiosk controller starts with. */
trait Concerns
{
    protected function actor(ServerRequestInterface $request): User
    {
        return RequestUtil::getActor($request);
    }

    protected function admin(ServerRequestInterface $request): User
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertAdmin();

        return $actor;
    }

    protected function param(ServerRequestInterface $request, string $name): string
    {
        return (string) Arr::get($request->getAttributes(), 'routeParameters.'.$name, '');
    }

    /** The id in the route, or 0 (not found) for anything that is not an id. */
    protected function routeId(ServerRequestInterface $request, string $name = 'id'): int
    {
        $id = $this->param($request, $name);

        return ctype_digit($id) && strlen($id) <= 10 && (int) $id <= 2147483647 ? (int) $id : 0;
    }

    /** @return array<string, mixed> */
    protected function body(ServerRequestInterface $request): array
    {
        return (array) Arr::get((array) $request->getParsedBody(), 'data', []);
    }

    /** @return array<string, mixed> */
    protected function query(ServerRequestInterface $request): array
    {
        return $request->getQueryParams();
    }
}
