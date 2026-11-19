<?php

namespace Ernestdefoe\Kiosk\Formatter;

use Ernestdefoe\Kiosk\Service\Gate;
use Flarum\Http\RequestUtil;
use Flarum\Post\CommentPost;
use Flarum\User\Guest;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The first post of a paid discussion, for someone who hasn't bought it: its
 * opening words only, as plain text. The front end fades it and shows the
 * offer underneath (the post carries `kioskLocked`).
 *
 * 🚨 Rendered with no request (notification emails, sitemaps, some SEO
 * tools), the reader is treated as a guest: locked. Failing closed means a
 * buyer's email may show the teaser, never that a stranger's shows the post.
 */
class TeaseContent
{
    private const WORDS = 60;

    public function __construct(private Gate $gate)
    {
    }

    public function __invoke(mixed $renderer, mixed $context, string $xml, ?ServerRequestInterface $request): string
    {
        if (! $context instanceof CommentPost || ! $context->discussion_id) {
            return $xml;
        }

        $actor = $request ? RequestUtil::getActor($request) : new Guest();

        if (! $this->gate->isLocked($actor, (int) $context->discussion_id, $context->user_id ? (int) $context->user_id : null)) {
            return $xml;
        }

        return '<t>'.htmlspecialchars(self::excerpt($xml), ENT_XML1).'</t>';
    }

    /** The opening words of a post as plain text, without its markup characters. */
    public static function excerpt(string $xml): string
    {
        // <s> and <e> hold the typed markup (**, [b], …); drop them before the tags go.
        $text = preg_replace('#<([se])>.*?</\1>#s', '', $xml) ?? '';
        $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace(['</p>', '<br/>'], ' ', $text)), ENT_QUOTES | ENT_XML1)) ?? '');
        $words = preg_split('/\s+/u', $text) ?: [];

        return count($words) > self::WORDS ? implode(' ', array_slice($words, 0, self::WORDS)).'…' : $text;
    }
}
