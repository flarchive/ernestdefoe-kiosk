<?php

namespace Ernestdefoe\Kiosk\Api\Admin;

use Ernestdefoe\Kiosk\Api\Concerns;
use Ernestdefoe\Kiosk\Api\WebhookController;
use Ernestdefoe\Kiosk\Model\WebhookEvent;
use Ernestdefoe\Kiosk\Provider\Providers;
use Ernestdefoe\Kiosk\Provider\WebhookProvider;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/** GET /kiosk/admin/webhooks — the delivery log. POST /{id}/replay — run one again. */
class WebhooksController implements RequestHandlerInterface
{
    use Concerns;

    public function __construct(private Providers $providers, private LoggerInterface $log)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->admin($request);

        if ($request->getMethod() === 'POST') {
            $row = WebhookEvent::query()->findOrFail($this->routeId($request));
            $account = $row->account_id ? \Ernestdefoe\Kiosk\Seller\Account::query()->find($row->account_id) : null;
            $provider = $account ? $this->providers->forAccount($account) : ($row->account_id ? null : $this->providers->get($row->provider));
            if ($provider instanceof WebhookProvider) {
                WebhookController::process($row, $provider, $this->log);
            }
            $rows = collect([$row->refresh()]);
        } else {
            $failed = ($this->query($request)['failed'] ?? '') === '1';
            $rows = WebhookEvent::query()
                ->when($failed, fn ($q) => $q->whereNotNull('error'))
                ->orderByDesc('id')
                ->limit(100)
                ->get(['id', 'provider', 'event_id', 'type', 'received_at', 'processed_at', 'error']);
        }

        return new JsonResponse(['data' => $rows->map(fn (WebhookEvent $e) => [
            'id' => $e->id,
            'provider' => $e->provider,
            'eventId' => $e->event_id,
            'type' => $e->type,
            'receivedAt' => $e->received_at?->toIso8601String(),
            'processedAt' => $e->processed_at?->toIso8601String(),
            'error' => $e->error,
        ])->values()->all()]);
    }
}
