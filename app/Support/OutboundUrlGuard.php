<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * Checks URLs people configure for outbound calls (self-hosted GitLab,
 * OAuth token endpoints, ticket webhooks) before Argos calls them from
 * inside its network. Refused: other schemes, loopback, link-local (cloud
 * metadata), unspecified and multicast addresses, and Argos' own services
 * (db, redis, app, workspace containers, …). Private ranges stay allowed —
 * a company GitLab usually lives there.
 */
final class OutboundUrlGuard
{
    /** Host names of the Argos stack and its containers. */
    private const INTERNAL_HOSTS = ['localhost', 'db', 'redis', 'app', 'nginx', 'traefik', 'reverb', 'queue', 'scheduler', 'phpmyadmin', 'worker-build'];

    private const INTERNAL_PREFIXES = ['argos-'];

    public static function assertAllowed(string $url): void
    {
        $reason = self::refusal($url);
        if ($reason !== null) {
            throw new RuntimeException(__('common.outbound_refused', ['url' => $url, 'reason' => $reason]));
        }
    }

    /** Why the URL is refused, or null when it may be called. */
    public static function refusal(string $url): ?string
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));
        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return 'scheme';
        }
        if (in_array($host, self::INTERNAL_HOSTS, true) || str_ends_with($host, '.localhost')) {
            return 'internal host';
        }
        foreach (self::INTERNAL_PREFIXES as $prefix) {
            if (str_starts_with($host, $prefix) && ! str_contains($host, '.')) {
                return 'internal host';
            }
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : self::resolve($host);
        foreach ($addresses as $ip) {
            if (self::blockedAddress($ip)) {
                return 'internal address';
            }
        }

        return null;
    }

    /** @return list<string> */
    private static function resolve(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        return array_values(array_filter(array_map(
            fn (array $record): ?string => $record['ip'] ?? $record['ipv6'] ?? null,
            is_array($records) ? $records : [],
        )));
    }

    private static function blockedAddress(string $ip): bool
    {
        if (str_starts_with($ip, '::ffff:')) {
            $ip = substr($ip, 7);
        }
        foreach (['127.0.0.0/8', '0.0.0.0/8', '169.254.0.0/16', '224.0.0.0/4', '::1/128', '::/128', 'fe80::/10', 'ff00::/8'] as $range) {
            if (self::inRange($ip, $range)) {
                return true;
            }
        }

        return false;
    }

    private static function inRange(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);
        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }
        $bytes = intdiv((int) $bits, 8);
        $rest = (int) $bits % 8;
        if (strncmp($ipBin, $subnetBin, $bytes) !== 0) {
            return false;
        }

        return $rest === 0 || (ord($ipBin[$bytes]) >> (8 - $rest)) === (ord($subnetBin[$bytes]) >> (8 - $rest));
    }
}
