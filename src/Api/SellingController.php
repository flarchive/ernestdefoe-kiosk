<?php

namespace Ernestdefoe\Kiosk\Api;

use Ernestdefoe\Kiosk\Model\Payment;
use Ernestdefoe\Kiosk\Model\Product;
use Ernestdefoe\Kiosk\Model\Purchase;
use Ernestdefoe\Kiosk\Seller\Account;
use Ernestdefoe\Kiosk\Seller\Connect;
use Ernestdefoe\Kiosk\Seller\Fees;
use Ernestdefoe\Kiosk\Seller\Listings;
use Ernestdefoe\Kiosk\Seller\Sellers;
use Ernestdefoe\Kiosk\Service\Presenter;
use Ernestdefoe\Kiosk\Service\Refunds;
use Ernestdefoe\Kiosk\Unlock\Registry;
use Ernestdefoe\Kiosk\Unlock\SellableByMembers;
use Flarum\Foundation\ValidationException;
use Flarum\Http\UrlGenerator;
use Flarum\Locale\TranslatorInterface;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * A member's Selling page: their payout accounts, their listings, their sales.
 * Every route is the member's own: listings and sales are looked up by
 * seller_id = the actor, never by id alone.
 */
class SellingController implements RequestHandlerInterface
{
    use Concerns;

    private const SALES = 50;

    public function __construct(
        private Sellers $sellers,
        private Connect $connect,
        private Listings $listings,
        private Registry $types,
        private Presenter $presenter,
        private Refunds $refunds,
        private Fees $fees,
        private UrlGenerator $url,
        private TranslatorInterface $translator,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $route = (string) $request->getAttribute('routeName');

        // BTCPay posts the new key back from the member's browser without
        // Flarum's session: the one-time state is the proof.
        if ($route === 'kiosk.selling.btcpay') {
            $account = Account::query()->where('provider', 'btcpay')->find($this->routeId($request, 'account'));
            $form = (array) $request->getParsedBody();
            $ok = $account && $this->connect->btcpayAuthorized($account, (string) ($request->getQueryParams()['state'] ?? ''),
                trim((string) ($form['apiKey'] ?? '')), array_values(array_map('strval', (array) ($form['permissions'] ?? []))));

            return new RedirectResponse($this->page().'?connected='.($ok ? 'btcpay' : 'failed'));
        }

        $actor = $this->actor($request);
        $actor->assertRegistered();
        if (! $this->sellers->maySell($actor)) {
            throw new PermissionDeniedException();
        }
        $body = $this->body($request);

        switch ($route) {
            case 'kiosk.selling.stripe':
                $account = Account::query()->where('user_id', $actor->id)->where('provider', 'stripe')->findOrFail($this->routeId($request, 'account'));
                if (isset($request->getQueryParams()['again'])) {
                    return new RedirectResponse($this->connect->stripe($actor));
                }
                $this->connect->stripeReturned($account);

                return new RedirectResponse($this->page().'?connected=stripe');

            case 'kiosk.selling.accounts.connect':
                $provider = $this->param($request, 'provider');
                $url = null;
                if ($provider === 'paypal') {
                    $this->connect->paypal($actor, trim((string) ($body['clientId'] ?? '')), trim((string) ($body['secret'] ?? '')));
                } elseif ($provider === 'nowpayments') {
                    $this->connect->nowpayments($actor, trim((string) ($body['apiKey'] ?? '')), trim((string) ($body['ipnSecret'] ?? '')));
                } elseif ($provider === 'stripe') {
                    $url = $this->connect->stripe($actor);
                } elseif ($provider === 'btcpay') {
                    $url = $this->connect->btcpay($actor, (string) ($body['url'] ?? ''));
                } else {
                    throw new ValidationException(['provider' => $this->translator->trans('ernestdefoe-kiosk.forum.selling.errors.provider_off')]);
                }

                return new JsonResponse(['data' => $this->overview($actor), 'meta' => ['redirect' => $url]]);

            case 'kiosk.selling.accounts.remove':
                $this->connect->remove($actor, $this->param($request, 'provider'));
                break;

            case 'kiosk.selling.terms':
                $actor->setPreference('kioskSellerTerms', mb_substr(trim((string) ($body['terms'] ?? '')), 0, 2000));
                $actor->save();
                break;

            case 'kiosk.selling.items':
                return new JsonResponse(['data' => $this->items($actor, (string) ($request->getQueryParams()['q'] ?? ''))]);

            case 'kiosk.selling.listings.save':
            case 'kiosk.selling.listings.save.id':
                $id = $this->routeId($request);
                $product = $id ? Product::query()->where('seller_id', $actor->id)->findOrFail($id) : null;
                $this->listings->save($actor, $product, $body);
                break;

            case 'kiosk.selling.listings.delete':
                $product = Product::query()->where('seller_id', $actor->id)->with('unlocks')->findOrFail($this->routeId($request));
                $unlocks = $product->unlocks;
                // A listing anyone bought is switched off, not deleted: their receipts point at it.
                if (Purchase::query()->where('product_id', $product->id)->exists()) {
                    $product->is_active = false;
                    $product->save();
                } else {
                    $product->delete();
                }
                // Then its items are given back (nothing sells them now).
                $product->setRelation('unlocks', $unlocks);
                $this->listings->released($product);
                break;

            case 'kiosk.selling.sales.refund':
                $purchase = Purchase::query()->where('seller_id', $actor->id)->where('number', $this->param($request, 'number'))->firstOrFail();
                if ($reason = $this->refunds->refund($purchase)) {
                    throw new ValidationException(['purchase' => $this->translator->trans('ernestdefoe-kiosk.admin.errors.'.$reason)]);
                }
                break;
        }

        if ($route === 'kiosk.selling.listings.delete' || $route === 'kiosk.selling.accounts.remove') {
            return new EmptyResponse(204);
        }

        return new JsonResponse(['data' => $this->overview($actor)]);
    }

    private function page(): string
    {
        return $this->url->to('forum')->route('kiosk.selling');
    }

    /** @return array<string, mixed> */
    private function overview(User $actor): array
    {
        $accounts = Account::query()->where('user_id', $actor->id)->get()->groupBy('provider');
        $ready = $this->sellers->readyAccounts([(int) $actor->id])[(int) $actor->id] ?? [];

        $listings = Product::query()->where('seller_id', $actor->id)->with(['unlocks', 'prices', 'seller'])->orderByDesc('id')->get();
        $sold = Purchase::query()->where('seller_id', $actor->id)->whereNotIn('status', [Purchase::PENDING, Purchase::EXPIRED])
            ->selectRaw('product_id, count(*) as n')->groupBy('product_id')->pluck('n', 'product_id');

        $sales = Purchase::query()->where('seller_id', $actor->id)->whereNotIn('status', [Purchase::PENDING, Purchase::EXPIRED])
            ->with(['payments', 'user'])->orderByDesc('id')->limit(self::SALES)->get();

        return [
            'trusted' => $actor->hasPermission(Sellers::TRUSTED),
            'feePercent' => $this->fees->percent(),
            'terms' => (string) $actor->getPreference('kioskSellerTerms', ''),
            'providers' => array_map(fn (string $key) => [
                'key' => $key,
                'status' => isset($ready[$key]) ? 'connected' : ($accounts->has($key) ? Account::PENDING : null),
                'hint' => ($accounts->get($key)?->first()?->details['hint'] ?? null),
            ], $this->sellers->providerKeys()),
            'listings' => array_map(fn (array $p, Product $model) => $p + [
                'review' => $model->review,
                'reviewNote' => $model->review_note,
                'active' => $model->is_active,
                'sold' => (int) ($sold[$model->id] ?? 0),
            ], $this->presenter->products($listings, $actor), $listings->all()),
            'sales' => $sales->map(fn (Purchase $p) => [
                'number' => $p->number,
                'productName' => $p->product_name,
                'amountCents' => $p->amount_cents,
                'currency' => $p->currency,
                'feeCents' => (int) $p->payments->sum('fee_cents'),
                'provider' => $p->provider,
                'status' => $p->status,
                'createdAt' => $p->created_at?->toIso8601String(),
                'buyer' => $p->user ? ['username' => $p->user->username, 'displayName' => $p->user->display_name] : null,
                'refundable' => $p->payments->contains(fn (Payment $pay) => $pay->status === Payment::COMPLETED) && $p->status !== Purchase::REFUNDED,
            ])->values()->all(),
        ];
    }

    /** @return list<array{type: string, id: int, label: string}> the member's own sellable things */
    private function items(User $actor, string $query): array
    {
        $out = [];
        foreach ($this->types->keys() as $key) {
            $type = $this->types->get($key);
            if ($type instanceof SellableByMembers) {
                foreach ($type->searchOwn($actor, mb_substr(trim($query), 0, 100), 20) as $item) {
                    $out[] = ['type' => $key, 'id' => (int) $item['id'], 'label' => (string) $item['label'], 'typeLabel' => $this->translator->trans($type->label())];
                }
            }
        }

        return $out;
    }
}
