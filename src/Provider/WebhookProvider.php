<?php

namespace Ernestdefoe\Kiosk\Provider;

/** A provider that tells Kiosk about payments by webhook. */
interface WebhookProvider
{
    /**
     * Whether the delivery genuinely came from the provider. Must fail closed.
     *
     * @param array<string, array<int, string>|string> $headers
     */
    public function verify(array $headers, string $rawBody): bool;

    /** @param array<string, mixed> $event */
    public function eventId(array $event): string;

    /** @param array<string, mixed> $event */
    public function eventType(array $event): string;

    /** @param array<string, mixed> $event */
    public function handle(array $event): void;
}
