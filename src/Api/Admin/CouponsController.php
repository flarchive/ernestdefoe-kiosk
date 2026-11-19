<?php

namespace Ernestdefoe\Kiosk\Api\Admin;

use Carbon\Carbon;
use Ernestdefoe\Kiosk\Api\Concerns;
use Ernestdefoe\Kiosk\Model\Coupon;
use Ernestdefoe\Kiosk\Model\Money;
use Flarum\Foundation\ValidationException;
use Flarum\Locale\TranslatorInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET    /kiosk/admin/coupons       — every code
 * POST   /kiosk/admin/coupons       — a new one
 * DELETE /kiosk/admin/coupons/{id}  — retire one (purchases keep the code they used)
 */
class CouponsController implements RequestHandlerInterface
{
    use Concerns;

    public function __construct(private TranslatorInterface $translator)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->admin($request);

        if ($request->getMethod() === 'DELETE') {
            (Coupon::query()->find($this->routeId($request)) ?? throw new ModelNotFoundException())->delete();

            return new EmptyResponse();
        }

        if ($request->getMethod() === 'POST') {
            return new JsonResponse(['data' => $this->present($this->create($this->body($request)))], 201);
        }

        return new JsonResponse(['data' => Coupon::query()->orderByDesc('id')->limit(500)->get()->map(fn (Coupon $c) => $this->present($c))->all()]);
    }

    /** @param array<string, mixed> $body */
    private function create(array $body): Coupon
    {
        $code = strtoupper(trim((string) ($body['code'] ?? '')));
        $kind = (string) ($body['kind'] ?? Coupon::PERCENT);
        $currency = strtoupper((string) ($body['currency'] ?? 'USD'));

        if (! preg_match('/^[A-Z0-9_-]{3,40}$/', $code)) {
            throw $this->invalid('code', 'coupon_code');
        }
        if (Coupon::query()->where('code', $code)->exists()) {
            throw $this->invalid('code', 'coupon_taken');
        }

        $value = $kind === Coupon::PERCENT ? (int) ($body['value'] ?? 0) : Money::toMinor($body['value'] ?? null, $currency);
        if (! in_array($kind, [Coupon::PERCENT, Coupon::AMOUNT], true) || $value === null || $value < 1 || ($kind === Coupon::PERCENT && $value > 100)
            || ($kind === Coupon::AMOUNT && ! in_array($currency, Money::CURRENCIES, true))) {
            throw $this->invalid('value', 'coupon_value');
        }

        $expires = trim((string) ($body['expiresAt'] ?? ''));
        try {
            $expiresAt = $expires === '' ? null : Carbon::parse($expires)->endOfDay();
        } catch (\Throwable) {
            throw $this->invalid('expiresAt', 'date');
        }

        $coupon = new Coupon();
        $coupon->code = $code;
        $coupon->kind = $kind;
        $coupon->value = $value;
        $coupon->currency = $kind === Coupon::AMOUNT ? $currency : null;
        $products = array_values(array_filter(array_map('intval', (array) ($body['productIds'] ?? []))));
        $coupon->product_ids = $products ? json_encode($products) : null;
        $coupon->max_uses = ($max = (int) ($body['maxUses'] ?? 0)) > 0 ? $max : null;
        $coupon->used_count = 0;
        $coupon->expires_at = $expiresAt;
        $coupon->created_at = Carbon::now();
        $coupon->save();

        return $coupon;
    }

    /** @return array<string, mixed> */
    private function present(Coupon $c): array
    {
        return [
            'id' => $c->id,
            'code' => $c->code,
            'kind' => $c->kind,
            'value' => $c->value,
            'currency' => $c->currency,
            'productIds' => $c->productIds(),
            'maxUses' => $c->max_uses,
            'usedCount' => $c->used_count,
            'expiresAt' => $c->expires_at?->toIso8601String(),
        ];
    }

    private function invalid(string $field, string $key): ValidationException
    {
        return new ValidationException([$field => $this->translator->trans('ernestdefoe-kiosk.admin.errors.'.$key)]);
    }
}
