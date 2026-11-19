<?php

namespace Ernestdefoe\Kiosk\Tests\integration;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/**
 * Stands in for PayPal's REST API: answers by method and path the way
 * PayPal does, and records every call so a test can say what was asked.
 */
class FakePayPal
{
    /** @var list<array{method: string, path: string, body: mixed, headers: array<string, mixed>}> */
    public array $calls = [];

    /** Overrides by "METHOD /path" (prefix match): status and body. @var array<string, array{0: int, 1: array<string, mixed>}> */
    public array $answers = [];

    public string $captureStatus = 'COMPLETED';
    public ?string $captureValue = null;
    public string $verification = 'SUCCESS';
    public string $subscriptionStatus = 'ACTIVE';
    public string $nextBilling = '';
    public string $planId = 'P-PLAN1';

    public function __invoke(RequestInterface $request): Response
    {
        $method = $request->getMethod();
        $path = $request->getUri()->getPath();
        $raw = (string) $request->getBody();
        $body = json_decode($raw, true) ?? $raw;
        $this->calls[] = ['method' => $method, 'path' => $path, 'body' => $body, 'headers' => $request->getHeaders()];

        foreach ($this->answers as $key => [$status, $json]) {
            if (str_starts_with("$method $path", $key)) {
                return $this->json($json, $status);
            }
        }

        return match (true) {
            $path === '/v1/oauth2/token' => $this->json(['access_token' => 'tok', 'expires_in' => 3600]),
            $path === '/v1/notifications/verify-webhook-signature' => $this->json(['verification_status' => $this->verification]),
            $method === 'GET' && $path === '/v1/notifications/webhooks' => $this->json(['webhooks' => []]),
            $method === 'POST' && $path === '/v1/notifications/webhooks' => $this->json(['id' => 'WH-1'], 201),
            $method === 'POST' && $path === '/v2/checkout/orders' => $this->json([
                'id' => 'ORDER-'.count($this->calls), 'status' => 'PAYER_ACTION_REQUIRED',
                'links' => [['rel' => 'payer-action', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=X']],
            ], 201),
            $method === 'POST' && str_ends_with($path, '/capture') => $this->captured($body, $path),
            $method === 'POST' && $path === '/v1/catalogs/products' => $this->json(['id' => 'PROD-1'], 201),
            $method === 'POST' && $path === '/v1/billing/plans' => $this->json(['id' => 'P-PLAN1'], 201),
            $method === 'POST' && $path === '/v1/billing/subscriptions' => $this->json([
                'id' => 'I-SUB1', 'status' => 'APPROVAL_PENDING',
                'links' => [['rel' => 'approve', 'href' => 'https://www.sandbox.paypal.com/webapps/billing/subscriptions?ba_token=X']],
            ], 201),
            $method === 'GET' && str_starts_with($path, '/v1/billing/subscriptions/') => $this->json([
                'id' => basename($path), 'status' => $this->subscriptionStatus, 'plan_id' => $this->planId, 'start_time' => gmdate('Y-m-d\TH:i:s\Z'),
                'billing_info' => ['next_billing_time' => $this->nextBilling ?: gmdate('Y-m-d\TH:i:s\Z', strtotime('+1 month'))],
            ]),
            $method === 'POST' && str_ends_with($path, '/cancel') => new Response(204),
            $method === 'POST' && str_ends_with($path, '/revise') => $this->json([
                'plan_id' => $body['plan_id'] ?? '', 'links' => [['rel' => 'approve', 'href' => 'https://www.sandbox.paypal.com/webapps/billing/subscriptions/update?ba_token=X']],
            ]),
            $method === 'GET' && str_starts_with($path, '/v1/billing/plans/') => $this->json(['id' => basename($path), 'product_id' => 'PROD-1']),
            str_ends_with($path, '/refund') => $this->json(['id' => 'REF-1', 'status' => 'COMPLETED'], 201),
            default => $this->json(['name' => 'NOT_FOUND'], 404),
        };
    }

    /** @param mixed $body */
    private function captured($body, string $path): Response
    {
        preg_match('#/orders/([^/]+)/capture#', $path, $m);

        return $this->json([
            'id' => $m[1] ?? 'ORDER', 'status' => $this->captureStatus === 'COMPLETED' ? 'COMPLETED' : 'PENDING',
            'purchase_units' => [['payments' => ['captures' => [[
                'id' => 'CAP-'.($m[1] ?? ''), 'status' => $this->captureStatus,
                'amount' => ['currency_code' => 'USD', 'value' => $this->captureValue ?? '5.00'],
            ]]]]],
        ], 201);
    }

    /** @return list<string> "METHOD /path" of every call */
    public function asked(): array
    {
        return array_map(fn ($c) => $c['method'].' '.$c['path'], $this->calls);
    }

    /** @param array<string, mixed> $json */
    private function json(array $json, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], (string) json_encode($json));
    }
}
