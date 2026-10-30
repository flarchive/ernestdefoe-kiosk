<?php

namespace Ernestdefoe\Kiosk\Tests\integration;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/** Stands in for api.stripe.com: answers by method and path, records every call. */
class FakeStripe
{
    /** @var list<array{method: string, path: string, params: array<string, mixed>, headers: array<string, mixed>}> */
    public array $calls = [];

    public string $paymentStatus = 'paid';
    public int $amountTotal = 500;
    public string $subscriptionStatus = 'active';
    public int $periodEnd = 0;
    public bool $cancelAtPeriodEnd = false;

    /** Overrides by "METHOD /path" (exact): the body to answer. @var array<string, array<string, mixed>> */
    public array $answers = [];

    /** Answers list calls by query: fn (path, params) => rows, or null to fall through. @var (callable(string, array<string, mixed>): ?array)|null */
    public $lister = null;

    public ?int $subtotal = null;

    public function __invoke(RequestInterface $request): Response
    {
        $method = $request->getMethod();
        $path = $request->getUri()->getPath();
        parse_str($method === 'GET' ? $request->getUri()->getQuery() : (string) $request->getBody(), $params);
        $this->calls[] = ['method' => $method, 'path' => $path, 'params' => $params, 'headers' => $request->getHeaders()];

        if ($method === 'GET' && $this->lister && ($rows = ($this->lister)($path, $params)) !== null) {
            return $this->json(['data' => $rows, 'has_more' => false]);
        }

        if (isset($this->answers["$method $path"])) {
            return $this->json($this->answers["$method $path"]);
        }

        return match (true) {
            $path === '/v1/account' => $this->json(['id' => 'acct_1']),
            $method === 'GET' && $path === '/v1/webhook_endpoints' => $this->json(['data' => [['id' => 'we_old', 'url' => 'http://localhost/api/kiosk/webhooks/stripe']]]),
            $method === 'DELETE' && str_starts_with($path, '/v1/webhook_endpoints/') => $this->json(['deleted' => true]),
            $method === 'POST' && $path === '/v1/webhook_endpoints' => $this->json(['id' => 'we_new', 'secret' => 'whsec_new']),
            $method === 'POST' && $path === '/v1/prices' => $this->json(['id' => 'price_'.count($this->calls)]),
            $method === 'POST' && $path === '/v1/checkout/sessions' => $this->json(['id' => 'cs_test_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_1']),
            $method === 'GET' && str_starts_with($path, '/v1/checkout/sessions/') => $this->json($this->session()),
            $method === 'GET' && str_starts_with($path, '/v1/subscriptions/') => $this->json($this->subscription()),
            $method === 'POST' && str_starts_with($path, '/v1/subscriptions/') => $this->json(['cancel_at_period_end' => true] + $this->subscription()),
            $method === 'DELETE' && str_starts_with($path, '/v1/subscriptions/') => $this->json(['status' => 'canceled'] + $this->subscription()),
            $method === 'POST' && $path === '/v1/refunds' => $this->json(['id' => 're_1', 'status' => 'succeeded']),
            default => $this->json(['error' => ['message' => 'No such route']], 404),
        };
    }

    public bool $subscriptionMode = false;

    /** @return array<string, mixed> */
    public function session(): array
    {
        return $this->subscriptionMode
            ? ['id' => 'cs_test_1', 'status' => 'complete', 'mode' => 'subscription', 'client_reference_id' => null, 'subscription' => $this->subscription() + ['latest_invoice' => $this->invoice()]]
            : ['id' => 'cs_test_1', 'status' => 'complete', 'mode' => 'payment', 'payment_status' => $this->paymentStatus, 'amount_total' => $this->amountTotal, 'amount_subtotal' => $this->subtotal ?? $this->amountTotal, 'currency' => 'usd', 'payment_intent' => 'pi_1'];
    }

    /** @return array<string, mixed> */
    public function subscription(): array
    {
        return [
            'id' => 'sub_1',
            'status' => $this->subscriptionStatus,
            'current_period_start' => time(),
            'current_period_end' => $this->periodEnd ?: strtotime('+1 month'),
            'cancel_at_period_end' => $this->cancelAtPeriodEnd,
            'items' => ['data' => [['id' => 'si_1']]],
            'metadata' => [],
        ];
    }

    /** @return array<string, mixed> */
    public function invoice(string $intent = 'pi_sub_1'): array
    {
        return ['id' => 'in_'.$intent, 'subscription' => 'sub_1', 'status' => 'paid', 'amount_paid' => 500, 'currency' => 'usd', 'payment_intent' => $intent];
    }

    /** @return list<string> */
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
