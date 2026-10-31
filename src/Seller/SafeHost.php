<?php

namespace Ernestdefoe\Kiosk\Seller;

/**
 * A member's own server (their BTCPay) is someone else's machine: Kiosk only
 * talks to it over https, at a public address, never following redirects,
 * pinned to the address checked here (so a DNS answer can't change between
 * the check and the request).
 */
final class SafeHost
{
    /** @return array{host: string, port: int, ip: string}|null null when it isn't a public https host */
    public static function resolve(string $url): ?array
    {
        $parts = parse_url($url);
        if (! is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $host = strtolower(trim($parts['host'], '[]'));
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips = [$host];
        } elseif (preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,63}$/', $host)) {
            $ips = array_merge(gethostbynamel($host) ?: [], array_column(@dns_get_record($host, DNS_AAAA) ?: [], 'ipv6'));
        } else {
            return null;
        }

        if (! $ips) {
            return null;
        }

        foreach ($ips as $ip) {
            if (! self::public($ip)) {
                return null;
            }
        }

        return ['host' => $host, 'port' => (int) ($parts['port'] ?? 443), 'ip' => $ips[0]];
    }

    private static function public(string $ip): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }

        // Not covered by the flags above: carrier-grade NAT, and IPv4 inside IPv6.
        $long = ip2long($ip);
        if ($long !== false && ($long & 0xFFC00000) === (ip2long('100.64.0.0') & 0xFFC00000)) {
            return false;
        }

        return ! preg_match('/^(::ffff:|64:ff9b:|2002:)/i', $ip);
    }

    /** Guzzle options for one request to a checked host. @param array{host: string, port: int, ip: string} $host */
    public static function options(array $host): array
    {
        return [
            'allow_redirects' => false,
            'timeout' => 6,
            'connect_timeout' => 3,
            'curl' => [CURLOPT_RESOLVE => [$host['host'].':'.$host['port'].':'.(str_contains($host['ip'], ':') ? '['.$host['ip'].']' : $host['ip'])]],
        ];
    }
}
