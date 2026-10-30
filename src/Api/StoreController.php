<?php

namespace Ernestdefoe\Kiosk\Api;

use Ernestdefoe\Kiosk\Model\Price;
use Carbon\Carbon;
use Ernestdefoe\Kiosk\Model\Product;
use Ernestdefoe\Kiosk\Model\Purchase;
use Ernestdefoe\Kiosk\Provider\Providers;
use Ernestdefoe\Kiosk\Service\Presenter;
use Flarum\Settings\SettingsRepositoryInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /kiosk/store — what is for sale. With ?type=&id= it answers "which
 * products unlock this item?" for a Buy button, hidden products included,
 * because a hidden product is exactly one sold only from its item.
 *
 * Everything is local rows: no provider is called to render the store.
 */
class StoreController implements RequestHandlerInterface
{
    use Concerns;

    public function __construct(private Presenter $presenter, private Providers $providers, private SettingsRepositoryInterface $settings)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->actor($request);
        $q = $this->query($request);
        $type = isset($q['type']) ? (string) $q['type'] : null;
        $id = isset($q['id']) ? (int) $q['id'] : 0;

        $products = Product::query()
            ->where('is_active', true)
            ->when($type !== null,
                fn ($query) => $query->whereIn('id', fn ($sub) => $sub->select('product_id')->from('kiosk_product_unlocks')
                    ->where(fn ($w) => $w->where('type', $type)->where('target_id', $id))
                    // A discussion is also opened by buying any paid tag it is in.
                    ->when($type === 'discussion', fn ($w) => $w->orWhere(fn ($tag) => $tag->where('type', 'tag')
                        ->whereIn('target_id', fn ($tags) => $tags->select('tag_id')->from('discussion_tag')->where('discussion_id', $id))))),
                fn ($query) => $query->where('is_hidden', false))
            ->with(['unlocks', 'prices'])
            ->orderBy('position')
            ->orderBy('id')
            ->limit(100)
            ->get()
            ->filter(fn (Product $p) => $p->prices->isNotEmpty());

        return new JsonResponse([
            'data' => $this->presenter->products($products, $actor),
            'meta' => [
                // Products the viewer holds right now, so a card says "You have
                // this" instead of selling it to them again. One query.
                'owned' => $actor->isGuest() ? [] : Purchase::query()
                    ->where('user_id', $actor->id)
                    ->whereIn('status', [Purchase::ACTIVE, Purchase::CANCELED])
                    ->where(fn ($q) => $q->whereNull('period_end')->orWhere('period_end', '>', Carbon::now()))
                    ->whereNotNull('product_id')
                    ->distinct()
                    ->pluck('product_id')
                    ->map(fn ($id) => (int) $id)
                    ->values()
                    ->all(),
                // `recurring`: whether it can take a subscription (crypto can't).
                'providers' => array_map(fn ($p) => ['key' => $p->key(), 'mode' => $p->mode(), 'recurring' => $p->supports(Price::monthly())], $this->providers->ready()),
                'termsUrl' => $this->settings->get('ernestdefoe-kiosk.terms_url') ?: null,
            ],
        ]);
    }
}
