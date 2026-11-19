<?php

namespace Ernestdefoe\Kiosk\Api\Admin;

use Ernestdefoe\Kiosk\Api\Concerns;
use Ernestdefoe\Kiosk\Model\Product;
use Ernestdefoe\Kiosk\Service\Presenter;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Members' listings: the queue of those waiting, and approve / reject (with a
 * reason the seller sees). ?review=all lists every member listing.
 */
class ListingsController implements RequestHandlerInterface
{
    use Concerns;

    public function __construct(private Presenter $presenter)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->admin($request);

        if ($request->getMethod() === 'POST') {
            $product = Product::query()->whereNotNull('seller_id')->findOrFail($this->routeId($request));
            $body = $this->body($request);
            $approve = ($body['action'] ?? '') === 'approve';
            $product->review = $approve ? null : Product::REJECTED;
            $product->review_note = $approve ? null : (mb_substr(trim((string) ($body['note'] ?? '')), 0, 255) ?: null);
            $product->save();
            $approve ? resolve(\Ernestdefoe\Kiosk\Seller\Listings::class)->approved($product) : resolve(\Ernestdefoe\Kiosk\Seller\Listings::class)->released($product);
        }

        $all = ($this->query($request)['review'] ?? '') === 'all';
        $products = Product::query()->whereNotNull('seller_id')
            ->when(! $all, fn ($q) => $q->where('review', Product::PENDING))
            ->with(['unlocks', 'prices', 'seller'])->orderByDesc('updated_at')->limit(200)->get();

        return new JsonResponse(['data' => array_map(fn (array $p, Product $m) => $p + [
            'review' => $m->review,
            'reviewNote' => $m->review_note,
            'active' => $m->is_active,
        ], $this->presenter->products($products, $actor), $products->all())]);
    }
}
