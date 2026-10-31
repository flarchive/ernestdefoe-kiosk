<?php

namespace Ernestdefoe\Kiosk\Api\Admin;

use Carbon\Carbon;
use Ernestdefoe\Kiosk\Api\Concerns;
use Ernestdefoe\Kiosk\Model\Payment;
use Ernestdefoe\Kiosk\Model\Price;
use Ernestdefoe\Kiosk\Model\Product;
use Ernestdefoe\Kiosk\Model\Purchase;
use Ernestdefoe\Kiosk\Model\WebhookEvent;
use Ernestdefoe\Kiosk\Provider\PayPalClient;
use Ernestdefoe\Kiosk\Provider\StripeClient;
use Ernestdefoe\Kiosk\Service\Presenter;
use Flarum\Http\UrlGenerator;
use Flarum\Settings\SettingsRepositoryInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /kiosk/admin/overview — the setup checklist, provider status and the
 * numbers, all from local rows (no provider call), so the admin page opens
 * instantly and works while PayPal is down.
 */
class OverviewController implements RequestHandlerInterface
{
    use Concerns;

    public function __construct(
        private PayPalClient $paypal,
        private StripeClient $stripe,
        private SettingsRepositoryInterface $settings,
        private UrlGenerator $url,
        private Presenter $presenter,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->admin($request);
        $now = Carbon::now();

        $live = Purchase::query()->whereIn('status', [Purchase::ACTIVE, Purchase::CANCELED])
            ->where(fn ($q) => $q->whereNull('period_end')->orWhere('period_end', '>', $now));

        // Monthly recurring revenue, per currency: each active subscription's
        // price spread over a month.
        $mrr = [];
        foreach ((clone $live)->where('status', Purchase::ACTIVE)->where('kind', Price::RECURRING)->where('provider', '!=', Purchase::GRANT)
            ->toBase()->get(['amount_cents', 'currency', 'interval', 'interval_count']) as $row) {
            $months = ['day' => 1 / 30.4375, 'week' => 7 / 30.4375, 'month' => 1, 'year' => 12][$row->interval] ?? 1;
            $mrr[$row->currency] = ($mrr[$row->currency] ?? 0) + (int) round($row->amount_cents / max(1, $row->interval_count * $months));
        }

        $month = Payment::query()->where('status', Payment::COMPLETED)->where('created_at', '>=', $now->copy()->startOfMonth())
            ->toBase()->selectRaw('currency, SUM(amount_cents) as total, COUNT(*) as n')->groupBy('currency')->get();

        $hasProduct = Product::query()->where('is_active', true)->exists();
        $hasSale = Purchase::query()->whereNotIn('status', [Purchase::PENDING, Purchase::EXPIRED])->where('provider', '!=', Purchase::GRANT)->exists();

        return new JsonResponse(['data' => [
            'paypal' => self::paypalStatus($this->paypal, $this->settings),
            'stripe' => StripeController::status($this->stripe, $this->settings),
            ...CryptoController::status(resolve(\Ernestdefoe\Kiosk\Provider\BTCPay::class), resolve(\Ernestdefoe\Kiosk\Provider\NOWPayments::class), $this->settings),
            'webhookUrl' => $this->url->to('api')->route('kiosk.webhooks', ['provider' => 'paypal']),
            'stripeWebhookUrl' => $this->url->to('api')->route('kiosk.webhooks', ['provider' => 'stripe']),
            'checklist' => [
                'provider' => $this->paypal->isConnected() || $this->stripe->isConnected() || resolve(\Ernestdefoe\Kiosk\Provider\Providers::class)->ready() !== [],
                'product' => $hasProduct,
                'sale' => $hasSale,
                'scheduler' => $this->settings->get('ernestdefoe-kiosk.last_sweep_at') !== null
                    && Carbon::parse((string) $this->settings->get('ernestdefoe-kiosk.last_sweep_at'))->isAfter($now->copy()->subHour()),
            ],
            'stats' => [
                'activeMembers' => (int) (clone $live)->distinct()->count('user_id'),
                'activeSubscriptions' => (int) (clone $live)->where('status', Purchase::ACTIVE)->where('kind', Price::RECURRING)->count(),
                'mrr' => $mrr,
                'thisMonth' => $month->map(fn ($r) => ['currency' => $r->currency, 'totalCents' => (int) $r->total, 'count' => (int) $r->n])->values()->all(),
                'failedWebhooks' => WebhookEvent::query()->whereNotNull('error')->whereNull('processed_at')->count(),
            ],
            'unlockTypes' => $this->presenter->unlockTypes(),
        ]]);
    }

    /** @return array<string, mixed> */
    public static function paypalStatus(PayPalClient $paypal, SettingsRepositoryInterface $settings): array
    {
        $out = ['mode' => $paypal->mode(), 'lastWebhookAt' => $settings->get('ernestdefoe-kiosk.paypal_last_webhook_at') ?: null];

        foreach (['sandbox', 'live'] as $mode) {
            $out[$mode] = [
                'clientId' => $paypal->setting('client_id', $mode),
                'hasSecret' => $paypal->setting('secret', $mode) !== '',
                'connected' => $paypal->isConnected($mode),
            ];
        }

        return $out;
    }
}
