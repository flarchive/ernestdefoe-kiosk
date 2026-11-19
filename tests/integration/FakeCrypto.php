<?php

namespace Ernestdefoe\Kiosk\Tests\integration;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/** Stands in for a BTCPay Server and NOWPayments: answers set per test by "METHOD /path" prefix. */
class FakeCrypto
{
    /** @var list<array{method: string, host: string, path: string, body: mixed, headers: array<string, mixed>}> */
    public array $calls = [];

    /** @var array<string, array{0: int, 1: array<mixed>}> */
    public array $answers = [];

    public function __invoke(RequestInterface $request): Response
    {
        $method = $request->getMethod();
        $path = $request->getUri()->getPath();
        $this->calls[] = ['method' => $method, 'host' => $request->getUri()->getHost(), 'path' => $path, 'body' => json_decode((string) $request->getBody(), true), 'headers' => $request->getHeaders()];

        $best = null;
        foreach ($this->answers as $key => $answer) {
            if (str_starts_with("$method $path", $key) && (! $best || strlen($key) > strlen($best))) {
                $best = $key;
            }
        }

        [$status, $json] = $best ? $this->answers[$best] : [404, ['message' => 'not found']];

        return new Response($status, ['Content-Type' => 'application/json'], (string) json_encode($json));
    }
}
