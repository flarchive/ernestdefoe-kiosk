<?php

namespace Ernestdefoe\Kiosk\Api;

use Carbon\Carbon;
use Ernestdefoe\Kiosk\Model\Purchase;
use Ernestdefoe\Kiosk\Service\Credits;
use Ernestdefoe\Kiosk\Service\Gate;
use Flarum\Discussion\Discussion;
use Flarum\Foundation\ValidationException;
use Flarum\Locale\TranslatorInterface;
use Flarum\User\Exception\PermissionDeniedException;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /kiosk/discussions/{id}/bump — spend a bump credit on your own discussion.
 * GET  /kiosk/discussions/{id}/tips — what a discussion has raised, and whether it can be backed.
 */
class ActionsController implements RequestHandlerInterface
{
    use Concerns;

    public function __construct(
        private Credits $credits,
        private Gate $gate,
        private ConnectionInterface $db,
        private TranslatorInterface $translator,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->actor($request);
        $discussion = Discussion::query()->whereVisibleTo($actor)->findOrFail($this->routeId($request));
        $tags = $this->gate->tagsOf((int) $discussion->id);

        if ($request->getMethod() === 'POST') {
            $actor->assertRegistered();

            if ((int) $discussion->user_id !== (int) $actor->id) {
                throw new PermissionDeniedException();
            }
            if (! $this->credits->spend($actor, 'bump', array_values(array_intersect($tags, $this->credits->tags()['bump'])))) {
                throw new ValidationException(['bump' => $this->translator->trans('ernestdefoe-kiosk.forum.errors.no_bumps')]);
            }

            $discussion->last_posted_at = Carbon::now();
            $discussion->save();

            return new JsonResponse(['data' => ['bumpedAt' => $discussion->last_posted_at->toIso8601String(), 'bumpsLeft' => $this->credits->bumpsFor($actor, $tags)]]);
        }

        $raised = $this->db->table('kiosk_purchases')
            ->where('context_type', 'discussion')->where('context_id', $discussion->id)
            ->whereIn('status', [Purchase::ACTIVE, Purchase::CANCELED, Purchase::ENDED])
            ->groupBy('currency')
            ->selectRaw('currency, SUM(amount_cents) as total, COUNT(DISTINCT user_id) as backers')
            ->get();

        return new JsonResponse(['data' => [
            'canTip' => (bool) array_intersect($tags, $this->credits->tags()['tip']),
            'raised' => $raised->map(fn ($r) => ['currency' => $r->currency, 'totalCents' => (int) $r->total, 'backers' => (int) $r->backers])->values()->all(),
        ]]);
    }
}
