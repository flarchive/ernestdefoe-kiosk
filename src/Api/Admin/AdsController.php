<?php

namespace Ernestdefoe\Kiosk\Api\Admin;

use Carbon\Carbon;
use Ernestdefoe\Kiosk\Api\Concerns;
use Ernestdefoe\Kiosk\Model\Ad;
use Ernestdefoe\Kiosk\Service\Ads;
use Flarum\Foundation\ValidationException;
use Flarum\Locale\TranslatorInterface;
use Illuminate\Contracts\Filesystem\Factory as Filesystem;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET    /kiosk/admin/ads           — every ad, with its totals
 * POST   /kiosk/admin/ads           — a new one
 * PATCH  /kiosk/admin/ads/{id}      — change one
 * DELETE /kiosk/admin/ads/{id}      — delete one, its picture and its counts
 * POST   /kiosk/admin/ads/{id}/image — upload its picture (multipart, field "image")
 */
class AdsController implements RequestHandlerInterface
{
    use Concerns;

    private const MAX_BYTES = 2 * 1024 * 1024;

    /** Twice a 728px banner: sharp on high-density screens, no bigger. */
    private const MAX_SIDE = 1456;

    /** No SVG: it can carry script. */
    private const TYPES = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp'];

    public function __construct(
        private TranslatorInterface $translator,
        private Ads $ads,
        private Filesystem $files,
        private ConnectionInterface $db,
        private ImageManager $images,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->admin($request);
        $method = $request->getMethod();

        if ($method === 'GET') {
            $totals = $this->db->table('kiosk_ad_stats')->groupBy('ad_id')
                ->selectRaw('ad_id, sum(impressions) as impressions, sum(clicks) as clicks')
                ->get()->keyBy('ad_id');

            return new JsonResponse(['data' => Ad::query()->orderByDesc('id')->limit(500)->get()
                ->map(fn (Ad $ad) => $this->present($ad, $totals->get($ad->id)))->all()]);
        }

        if ($method === 'POST' && ! $this->param($request, 'id')) {
            $ad = new Ad();
            $this->fill($ad, $this->body($request));
            $ad->save();
            $this->ads->forget();

            return new JsonResponse(['data' => $this->present($ad)], 201);
        }

        $ad = Ad::query()->find($this->routeId($request)) ?? throw new ModelNotFoundException();

        if ($method === 'DELETE') {
            $this->dropImage($ad);
            $ad->delete();
            $this->ads->forget();

            return new EmptyResponse();
        }

        if ($method === 'POST') {
            $this->upload($ad, $request->getUploadedFiles()['image'] ?? null);
        } else {
            $this->fill($ad, $this->body($request));
        }

        $ad->save();
        $this->ads->forget();

        return new JsonResponse(['data' => $this->present($ad)]);
    }

    /** @param array<string, mixed> $body */
    private function fill(Ad $ad, array $body): void
    {
        $name = trim((string) ($body['name'] ?? $ad->name ?? ''));
        if ($name === '' || mb_strlen($name) > 150) {
            throw $this->invalid('name', 'ad_name');
        }

        $location = (string) ($body['location'] ?? $ad->location ?? '');
        if (! in_array($location, Ad::LOCATIONS, true)) {
            throw $this->invalid('location', 'ad_location');
        }

        $kind = (string) ($body['kind'] ?? $ad->kind ?? Ad::IMAGE);
        if (! in_array($kind, [Ad::IMAGE, Ad::HTML], true)) {
            throw $this->invalid('kind', 'ad_kind');
        }

        $audience = (string) ($body['audience'] ?? $ad->audience ?? 'everyone');
        if (! in_array($audience, Ad::AUDIENCES, true)) {
            throw $this->invalid('audience', 'ad_audience');
        }

        $link = array_key_exists('linkUrl', $body) ? trim((string) $body['linkUrl']) : (string) $ad->link_url;
        // Only web links: the click goes through our redirect to exactly this.
        if ($link !== '' && (mb_strlen($link) > 500 || ! preg_match('~^https?://[^\s/$.?#][^\s]*$~i', $link))) {
            throw $this->invalid('linkUrl', 'ad_link');
        }

        $html = array_key_exists('html', $body) ? trim((string) $body['html']) : (string) $ad->html;
        if ($kind === Ad::HTML && ($html === '' || strlen($html) > 10000)) {
            throw $this->invalid('html', 'ad_html');
        }

        $ad->name = $name;
        $ad->location = $location;
        $ad->kind = $kind;
        $ad->audience = $audience;
        $ad->link_url = $link === '' ? null : $link;
        $ad->alt_text = array_key_exists('altText', $body) ? (mb_substr(trim((string) $body['altText']), 0, 191) ?: null) : $ad->alt_text;
        $ad->html = $kind === Ad::HTML ? $html : null;
        if ($kind === Ad::HTML) {
            $this->dropImage($ad);
            $ad->image_path = $ad->image_width = $ad->image_height = null;
        }
        $ad->is_active = array_key_exists('active', $body) ? (bool) $body['active'] : ($ad->exists ? $ad->is_active : true);
        $ad->starts_at = $this->date($body, 'startsAt', $ad->starts_at, false);
        $ad->ends_at = $this->date($body, 'endsAt', $ad->ends_at, true);

        if ($ad->starts_at && $ad->ends_at && $ad->ends_at <= $ad->starts_at) {
            throw $this->invalid('endsAt', 'ad_dates');
        }
    }

    /** @param array<string, mixed> $body */
    private function date(array $body, string $key, ?Carbon $current, bool $end): ?Carbon
    {
        if (! array_key_exists($key, $body)) {
            return $current;
        }

        $value = trim((string) $body[$key]);
        if ($value === '') {
            return null;
        }

        try {
            $date = Carbon::parse($value);
        } catch (\Throwable) {
            throw $this->invalid($key, 'date');
        }

        // A bare date runs the whole day.
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $date = $end ? $date->endOfDay() : $date->startOfDay();
        }

        return $date;
    }

    private function upload(Ad $ad, mixed $file): void
    {
        if (! $file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK || $file->getSize() > self::MAX_BYTES) {
            throw $this->invalid('image', 'ad_image');
        }

        $bytes = (string) $file->getStream();
        $info = strlen($bytes) <= self::MAX_BYTES ? @getimagesizefromstring($bytes) : false;
        $ext = $info ? (self::TYPES[$info['mime']] ?? null) : null;

        if (! $info || ! $ext) {
            throw $this->invalid('image', 'ad_image');
        }

        // Re-encoded, never stored as sent: drops anything riding inside the
        // file, and sizes it down for every visitor who will load it.
        try {
            $image = $this->images->read($bytes)->scaleDown(self::MAX_SIDE, self::MAX_SIDE);
            $encoded = (string) $image->encodeByExtension($ext, quality: 85);
        } catch (\Throwable) {
            throw $this->invalid('image', 'ad_image');
        }

        $path = 'kiosk-ads/'.Str::random(24).'.'.$ext;
        $this->files->disk('flarum-assets')->put($path, $encoded);

        $this->dropImage($ad);
        $ad->image_path = $path;
        $ad->image_width = $image->width();
        $ad->image_height = $image->height();
    }

    private function dropImage(Ad $ad): void
    {
        if ($ad->image_path) {
            $this->files->disk('flarum-assets')->delete($ad->image_path);
        }
    }

    /** @return array<string, mixed> */
    private function present(Ad $ad, ?object $totals = null): array
    {
        return [
            'id' => $ad->id,
            'name' => $ad->name,
            'location' => $ad->location,
            'kind' => $ad->kind,
            'imageUrl' => $ad->image_path ? $this->files->disk('flarum-assets')->url($ad->image_path) : null,
            'imageWidth' => $ad->image_width,
            'imageHeight' => $ad->image_height,
            'linkUrl' => $ad->link_url,
            'altText' => $ad->alt_text,
            'html' => $ad->html,
            'audience' => $ad->audience,
            'active' => (bool) $ad->is_active,
            'startsAt' => $ad->starts_at?->toIso8601String(),
            'endsAt' => $ad->ends_at?->toIso8601String(),
            'impressions' => (int) ($totals->impressions ?? 0),
            'clicks' => (int) ($totals->clicks ?? 0),
        ];
    }

    private function invalid(string $field, string $key): ValidationException
    {
        return new ValidationException([$field => $this->translator->trans('ernestdefoe-kiosk.admin.errors.'.$key)]);
    }
}
