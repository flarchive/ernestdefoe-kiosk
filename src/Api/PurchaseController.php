<?php

namespace Ernestdefoe\Kiosk\Api;

use Ernestdefoe\Kiosk\Model\Price;
use Ernestdefoe\Kiosk\Model\Purchase;
use Ernestdefoe\Kiosk\Provider\Providers;
use Ernestdefoe\Kiosk\Service\Fulfilment;
use Ernestdefoe\Kiosk\Service\Presenter;
use Ernestdefoe\Kiosk\Service\Seats;
use Flarum\Http\UrlGenerator;
use Flarum\Foundation\ValidationException;
use Flarum\Locale\TranslatorInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The buyer's own purchases.
 *
 * GET  /kiosk/purchases               — everything they have bought
 * GET  /kiosk/purchases/{number}      — one, for the return page to poll
 * POST /kiosk/purchases/{number}/confirm — back from PayPal: ask it what happened
 * POST /kiosk/purchases/{number}/cancel  — stop a subscription renewing
 * POST /kiosk/purchases/{number}/switch  {priceId} — move to another plan
 * POST /kiosk/purchases/{number}/seats   {username} — give a team seat
 * DELETE /kiosk/purchases/{number}/seats/{userId} — take one back
 *
 * Listed: what they own, gifts they gave, and seats they were given.
 */
class PurchaseController implements RequestHandlerInterface
{
    use Concerns;

    public function __construct(
        private Presenter $presenter,
        private Providers $providers,
        private Fulfilment $fulfilment,
        private TranslatorInterface $translator,
        private Seats $seats,
        private UrlGenerator $url,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->actor($request);
        $actor->assertRegistered();
        $number = $this->param($request, 'number');

        if ($number === '') {
            $purchases = Purchase::query()
                ->where(fn ($q) => $q->where('user_id', $actor->id)->orWhere('gifted_by', $actor->id)
                    ->orWhereIn('id', fn ($s) => $s->select('purchase_id')->from('kiosk_seats')->where('user_id', $actor->id)))
                ->whereNotIn('status', [Purchase::PENDING, Purchase::EXPIRED])
                ->with(['entitlements', 'payments'])
                ->orderByDesc('id')
                ->limit(200)
                ->get();

            return new JsonResponse(['data' => $this->presenter->purchases($purchases, false, $actor)]);
        }

        // Someone else's number is "not found", the same as a wrong one. The
        // giver of a gift may follow it through checkout; only the owner
        // manages it after that.
        $purchase = Purchase::query()->where('number', $number)
            ->where(fn ($q) => $q->where('user_id', $actor->id)->orWhere('gifted_by', $actor->id))->first()
            ?? throw new ModelNotFoundException();

        $route = (string) $request->getAttribute('routeName');
        $owner = $purchase->user_id === (int) $actor->id;

        if (! $owner && ! str_ends_with($route, '.confirm') && ! str_ends_with($route, '.show')) {
            throw new ModelNotFoundException();
        }

        if (str_ends_with($route, '.confirm')) {
            $provider = $this->providers->get($purchase->provider);
            // Back from approving a plan switch: read the subscription again.
            if ($purchase->status === Purchase::PENDING) {
                $provider?->confirm($purchase);
            } elseif ($owner) {
                $provider?->reconcile($purchase);
            }
            $purchase->refresh();
        } elseif (str_ends_with($route, '.cancel')) {
            if (! $purchase->isRecurring() || $purchase->status !== Purchase::ACTIVE || $purchase->provider === Purchase::GRANT) {
                throw new ValidationException(['purchase' => $this->translator->trans('ernestdefoe-kiosk.forum.errors.cannot_cancel')]);
            }
            if (! $this->providers->get($purchase->provider)?->cancel($purchase)) {
                throw new ValidationException(['purchase' => $this->translator->trans('ernestdefoe-kiosk.forum.errors.provider_unreachable')]);
            }
            $this->fulfilment->cancelAtPeriodEnd($purchase);
            $purchase->refresh();
        } elseif (str_ends_with($route, '.switch')) {
            $to = Price::query()->whereNull('archived_at')->where('product_id', $purchase->product_id)->find((int) ($this->body($request)['priceId'] ?? 0));
            $provider = $this->providers->get($purchase->provider);

            // 🚨 PayPal bills a new plan from the next cycle: more seats now
            // would be used before they're paid for. Stripe charges at once.
            if ($to && $purchase->provider === 'paypal' && (int) $to->seats > $purchase->seats) {
                throw new ValidationException(['purchase' => $this->translator->trans('ernestdefoe-kiosk.forum.errors.switch_seats_paypal')]);
            }

            if (! $to || ! $to->isRecurring() || $to->id === $purchase->price_id || $purchase->status !== Purchase::ACTIVE || $purchase->cancel_at_period_end || ! $provider || ! $provider->supports($to)) {
                throw new ValidationException(['purchase' => $this->translator->trans('ernestdefoe-kiosk.forum.errors.cannot_switch')]);
            }

            $approve = $provider->switchPlan($purchase, $to, $this->url->to('forum')->route('kiosk.return', ['number' => $purchase->number]).'?switched=1');

            if ($approve) {
                return new JsonResponse(['data' => ['url' => $approve]]);
            }
            $purchase->refresh();
        } elseif (str_ends_with($route, '.seats.add')) {
            $this->seats->add($purchase, (string) ($this->body($request)['username'] ?? ''));
        } elseif (str_ends_with($route, '.seats.remove')) {
            $this->seats->remove($purchase, $this->routeId($request, 'userId'));
        }

        $purchase->load(['entitlements', 'payments']);

        return new JsonResponse(['data' => $this->presenter->purchases([$purchase], false, $actor)[0]]);
    }
}
