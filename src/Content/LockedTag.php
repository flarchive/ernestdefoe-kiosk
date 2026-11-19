<?php

namespace Ernestdefoe\Kiosk\Content;

use Ernestdefoe\Kiosk\Service\PaidTags;
use Flarum\Frontend\Document;
use Flarum\Http\RequestUtil;
use Flarum\Tags\Content\Tag as TagContent;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Arr;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * The /t/{slug} page, wrapped.
 *
 * flarum/tags answers a tag the visitor cannot see with "not found", which is
 * right for a private tag and wrong for one that is for sale: a shared link to
 * a premium section should say what it is and how to join. So the tags page
 * runs exactly as before, and only when it says "not found" for a paid tag is
 * the ordinary forum page served instead, titled with the tag. The forum's
 * JavaScript then shows the offer (see lockedTags.tsx). The tag's discussions
 * are never loaded or sent.
 */
class LockedTag
{
    public function __construct(private Container $container, private PaidTags $paidTags)
    {
    }

    public function __invoke(Document $document, Request $request): Document
    {
        try {
            return $this->container->make(TagContent::class)($document, $request);
        } catch (ModelNotFoundException $e) {
            $slug = urldecode((string) Arr::get($request->getQueryParams(), 'slug', ''));

            foreach ($this->paidTags->lockedFor(RequestUtil::getActor($request)) as $tag) {
                if (strcasecmp($tag['slug'], $slug) === 0) {
                    $document->title = $tag['name'];
                    $document->meta['description'] = (string) ($tag['description'] ?? '');

                    return $document;
                }
            }

            throw $e;
        }
    }
}
