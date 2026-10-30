<?php

namespace Ernestdefoe\Kiosk\Api\Admin;

use Ernestdefoe\Kiosk\Api\Concerns;
use Ernestdefoe\Kiosk\Model\Product;
use Ernestdefoe\Kiosk\Service\PaidTags;
use Ernestdefoe\Kiosk\Service\Presenter;
use Ernestdefoe\Kiosk\Service\ProductSaver;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The admin's products: GET list, POST create, PATCH /{id}, DELETE /{id},
 * POST /order {ids}.
 *
 * Deleting a product never takes anything from people who bought it: their
 * purchases keep its name and their entitlements keep their items.
 */
class ProductsController implements RequestHandlerInterface
{
    use Concerns;

    public function __construct(private ProductSaver $saver, private Presenter $presenter, private PaidTags $paidTags)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->admin($request);
        $method = $request->getMethod();
        $route = (string) $request->getAttribute('routeName');

        if ($route === 'kiosk.admin.products.order') {
            foreach (array_values((array) ($this->body($request)['ids'] ?? [])) as $i => $id) {
                Product::query()->whereKey((int) $id)->update(['position' => $i]);
            }
        } elseif ($method === 'POST') {
            $product = $this->saver->save(null, $this->body($request));

            return new JsonResponse(['data' => $this->presenter->products([$product], $actor, true)[0]], 201);
        } elseif ($method === 'PATCH') {
            $product = $this->saver->save(Product::query()->findOrFail($this->routeId($request)), $this->body($request));

            return new JsonResponse(['data' => $this->presenter->products([$product], $actor, true)[0]]);
        } elseif ($method === 'DELETE') {
            Product::query()->findOrFail($this->routeId($request))->delete();
            $this->paidTags->forget();
            resolve(\Ernestdefoe\Kiosk\Service\Gate::class)->forget();
            resolve(\Ernestdefoe\Kiosk\Service\Credits::class)->forget();
            resolve(\Ernestdefoe\Kiosk\Service\Ads::class)->forget();

            return new EmptyResponse(204);
        }

        $products = Product::query()->with(['unlocks', 'prices'])->orderBy('position')->orderBy('id')->get();

        return new JsonResponse(['data' => $this->presenter->products($products, $actor, true)]);
    }
}
