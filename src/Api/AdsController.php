<?php

namespace Ernestdefoe\Kiosk\Api;

use Ernestdefoe\Kiosk\Service\Ads;
use Illuminate\Contracts\Cache\Repository as Cache;
use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /kiosk/ads/seen        — {seen: {adId: count}}, sent in batches by the page
 * POST /kiosk/ads/{id}/click  — the page reports a click; the link itself goes straight to the advertiser
 *
 * Counts are a guide, not billing: each address may report 12 batches a
 * minute and one click per ad per 10 minutes, and only live ads count.
 * 🚨 Both are POST (with Flarum's CSRF token): a GET could be counted by an
 * <img> pointing at it in any post.
 */
class AdsController implements RequestHandlerInterface
{
    use Concerns;

    public function __construct(private Ads $ads, private Cache $cache)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $ip = sha1((string) $request->getAttribute('ipAddress'));

        $id = $this->routeId($request);

        if (! $id) {
            $key = 'kiosk.ads.seen.'.$ip.'.'.intdiv(time(), 60);
            $batches = (int) $this->cache->get($key, 0);

            if ($batches < 12) {
                $this->cache->put($key, $batches + 1, 120);
                $live = array_flip($this->ads->liveIds());
                $counts = [];

                foreach (array_slice((array) ($this->body($request)['seen'] ?? []), 0, 20, true) as $id => $n) {
                    if (isset($live[(int) $id])) {
                        $counts[(int) $id] = max(1, min(10, (int) $n));
                    }
                }

                $this->ads->seen($counts);
            }

            return new EmptyResponse();
        }

        if (in_array($id, $this->ads->liveIds(), true) && $this->cache->add('kiosk.ads.click.'.$ip.'.'.$id, 1, 600)) {
            $this->ads->clicked($id);
        }

        return new EmptyResponse();
    }
}
