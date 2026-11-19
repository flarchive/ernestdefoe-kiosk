<?php

namespace Ernestdefoe\Kiosk\Api\Admin;

use Ernestdefoe\Kiosk\Api\Concerns;
use Ernestdefoe\Kiosk\Model\Payment;
use Ernestdefoe\Kiosk\Model\Product;
use Ernestdefoe\Kiosk\Model\Purchase;
use Ernestdefoe\Kiosk\Provider\Providers;
use Ernestdefoe\Kiosk\Service\Fulfilment;
use Ernestdefoe\Kiosk\Service\Presenter;
use Flarum\Foundation\ValidationException;
use Flarum\Locale\TranslatorInterface;
use Flarum\User\User;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET  /kiosk/admin/purchases?q=&status=&product=&offset=
 * POST /kiosk/admin/purchases/{id}/cancel — stop renewing, keep the paid period
 * POST /kiosk/admin/purchases/{id}/end    — take access away now
 * POST /kiosk/admin/purchases/{id}/refund — give the money back and end it
 * POST /kiosk/admin/grants {user, productId, days} — give a product away
 */
class PurchasesController implements RequestHandlerInterface
{
    use Concerns;

    private const PAGE = 50;

    public function __construct(
        private Presenter $presenter,
        private Providers $providers,
        private Fulfilment $fulfilment,
        private TranslatorInterface $translator,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->admin($request);
        $route = (string) $request->getAttribute('routeName');

        if ($route === 'kiosk.admin.grants') {
            return $this->grant($this->body($request));
        }

        if ($route !== 'kiosk.admin.purchases') {
            $purchase = Purchase::query()->findOrFail($this->routeId($request));
            $this->act($purchase, substr($route, strlen('kiosk.admin.purchases.')));
            $purchase->refresh()->load(['entitlements', 'payments', 'user']);

            return new JsonResponse(['data' => $this->presenter->purchases([$purchase], true)[0]]);
        }

        $q = $this->query($request);
        $offset = max(0, (int) ($q['offset'] ?? 0));
        $search = trim((string) ($q['q'] ?? ''));

        $query = Purchase::query()
            ->when(($q['status'] ?? '') !== '',
                fn ($query) => $query->where('status', (string) $q['status']),
                // Abandoned checkouts are noise unless asked for.
                fn ($query) => $query->whereNotIn('status', [Purchase::PENDING, Purchase::EXPIRED]))
            ->when(($q['product'] ?? '') !== '', fn ($query) => $query->where('product_id', (int) $q['product']))
            ->when($search !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('number', $search)
                ->orWhere('provider_ref', $search)
                ->orWhereIn('user_id', User::query()->select('id')
                    ->where('username', 'like', addcslashes($search, '%_\\').'%')
                    ->orWhere('email', $search))));

        $rows = $query->with(['entitlements', 'payments', 'user'])->orderByDesc('id')->offset($offset)->limit(self::PAGE + 1)->get();

        return new JsonResponse([
            'data' => $this->presenter->purchases($rows->take(self::PAGE), true),
            'meta' => ['next' => $rows->count() > self::PAGE ? $offset + self::PAGE : null],
        ]);
    }

    private function act(Purchase $purchase, string $action): void
    {
        $provider = $purchase->provider === Purchase::GRANT ? null : $this->providers->forPurchase($purchase);

        switch ($action) {
            case 'cancel':
                if ($purchase->isRecurring() && $provider && ! $provider->cancel($purchase)) {
                    throw $this->error('provider_unreachable');
                }
                $this->fulfilment->cancelAtPeriodEnd($purchase);
                break;

            case 'end':
                if ($purchase->isRecurring() && $provider && $purchase->status === Purchase::ACTIVE) {
                    $provider->cancel($purchase);
                }
                $this->fulfilment->end($purchase);
                break;

            case 'refund':
                if ($reason = resolve(\Ernestdefoe\Kiosk\Service\Refunds::class)->refund($purchase)) {
                    throw $this->error($reason);
                }
                break;
        }
    }

    /** @param array<string, mixed> $body */
    private function grant(array $body): ResponseInterface
    {
        $who = trim((string) ($body['user'] ?? ''));
        $user = User::query()->where('username', $who)->orWhere('email', $who)->first();
        $product = Product::query()->with('prices')->find((int) ($body['productId'] ?? 0));

        if (! $user) {
            throw new ValidationException(['user' => $this->translator->trans('ernestdefoe-kiosk.admin.errors.no_such_user')]);
        }
        if (! $product) {
            throw new ValidationException(['productId' => $this->translator->trans('ernestdefoe-kiosk.admin.errors.no_such_product')]);
        }

        $days = max(0, min(36500, (int) ($body['days'] ?? 0)));
        $purchase = $this->fulfilment->grant($user, $product, $days, isset($body['note']) ? (string) $body['note'] : null);
        $purchase->load(['entitlements', 'payments', 'user']);

        return new JsonResponse(['data' => $this->presenter->purchases([$purchase], true)[0]], 201);
    }

    private function error(string $key): ValidationException
    {
        return new ValidationException(['purchase' => $this->translator->trans('ernestdefoe-kiosk.admin.errors.'.$key)]);
    }
}
