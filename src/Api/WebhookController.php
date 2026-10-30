<?php

namespace Ernestdefoe\Kiosk\Api;

use Carbon\Carbon;
use Ernestdefoe\Kiosk\Model\WebhookEvent;
use Ernestdefoe\Kiosk\Provider\Providers;
use Ernestdefoe\Kiosk\Provider\WebhookProvider;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Database\QueryException;
use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * POST /kiosk/webhooks/{provider} — a payment provider telling us something happened.
 *
 * 🚨 A public URL that hands out paid access: nothing is read until the
 * provider's signature checks out. Then the event is stored — the unique
 * (provider, event_id) index answers "already have it" to a retry — and acted
 * on. A failure is stored with its error and answered 500, so the provider
 * retries and the admin can see it and press Run again.
 */
class WebhookController implements RequestHandlerInterface
{
    use Concerns;

    public function __construct(
        private Providers $providers,
        private SettingsRepositoryInterface $settings,
        private LoggerInterface $log,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $key = $this->param($request, 'provider');
        $provider = $this->providers->get($key);

        if (! $provider instanceof WebhookProvider) {
            return new EmptyResponse(404);
        }

        $raw = (string) $request->getBody();

        if (strlen($raw) > 1_000_000 || ! $provider->verify($request->getHeaders(), $raw)) {
            $this->log->warning("[kiosk] refused a $key webhook that did not verify");

            return new EmptyResponse(400);
        }

        $event = (array) json_decode($raw, true);
        $id = $provider->eventId($event);

        if ($id === '') {
            return new EmptyResponse(400);
        }

        $this->settings->set("ernestdefoe-kiosk.{$key}_last_webhook_at", Carbon::now()->toIso8601String());

        $row = WebhookEvent::query()->where('provider', $key)->where('event_id', $id)->first();

        if ($row?->processed_at) {
            return new EmptyResponse(200);
        }

        if (! $row) {
            $row = new WebhookEvent();
            $row->provider = $key;
            $row->event_id = mb_substr($id, 0, 191);
            $row->type = mb_substr($provider->eventType($event), 0, 100);
            $row->payload = $raw;
            $row->received_at = Carbon::now();

            try {
                $row->save();
            } catch (QueryException) {
                // The same event arriving twice at once: the other request has it.
                return new EmptyResponse(200);
            }
        }

        return self::process($row, $provider, $this->log) ? new EmptyResponse(200) : new EmptyResponse(500);
    }

    /** Act on a stored event; the admin's Run again button comes through here too. */
    public static function process(WebhookEvent $row, WebhookProvider $provider, LoggerInterface $log): bool
    {
        try {
            $provider->handle((array) json_decode($row->payload, true));
            $row->processed_at = Carbon::now();
            $row->error = null;
            $row->save();

            return true;
        } catch (\Throwable $e) {
            $log->error('[kiosk] '.$row->provider.' webhook '.$row->event_id.' ('.$row->type.') failed: '.$e->getMessage());
            $row->error = mb_substr($e->getMessage(), 0, 500);
            $row->save();

            return false;
        }
    }
}
