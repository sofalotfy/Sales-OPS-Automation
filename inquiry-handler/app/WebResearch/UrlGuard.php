<?php

namespace App\WebResearch;

use Throwable;

/**
 * SSRF guard for the web-research agent (research R3, feature 010).
 *
 * A candidate URL is "safe to fetch" ONLY when ALL of these hold:
 *
 *   1. scheme is http or https, and the effective port equals the scheme's
 *      default (80 for http, 443 for https). Any other port is rejected
 *      outright — that also refuses `db:5432`, `auth-service:8000`, arbitrary
 *      service ports, and so on.
 *   2. the host is NOT an IP literal (bare IPv4, bare IPv6, or the
 *      IPv4-mapped form `::ffff:a.b.c.d`). Leaf URLs use hostnames, so any
 *      literal is refused — including mapped forms that trip up naive
 *      "filter_var(FILTER_VALIDATE_IP)" checks.
 *   3. the hostname is NOT single-label. That rejects `localhost` AND every
 *      docker-compose service name (`db`, `auth-service`, `work-scope-rag`,
 *      `inquiry-handler`, `dashboard`, ...) in one rule.
 *   4. the hostname does not end in `.local`, `.internal`, or `.localhost`,
 *      and is not one of our own docker service names (defence in depth).
 *   5. EVERY A and AAAA record for the host resolves to a PUBLIC address.
 *      Rejected: 10/8, 172.16/12, 192.168/16 (RFC1918); 127/8 (loopback);
 *      169.254/16 including 169.254.169.254 (link-local / cloud metadata);
 *      CGNAT 100.64/10; 0.0.0.0/8; 224/4 multicast; 240/4 reserved; the
 *      TEST-NET / documentation ranges; IPv6 ::, ::1, fc00::/7 (ULA),
 *      fe80::/10 (link-local), ff00::/8 (multicast), 2001:db8::/32 (docs),
 *      and IPv4-mapped ::ffff:a.b.c.d whose embedded IPv4 is non-public.
 *
 * DNS resolution is injectable so tests never touch real DNS and so the
 * caller can RE-RESOLVE + RE-VALIDATE at EVERY redirect hop (DNS-rebinding
 * defence). The entry point returns the exact validated target — host, port,
 * and a single public IP the caller MUST pin the connection to — or `null`
 * to FAIL CLOSED (callers treat null exactly like any other fetch failure:
 * skip that source, keep the batch going).
 */
class UrlGuard
{
    /** @var list<array{0: string, 1: int}>  [base, bits] */
    private const PRIVATE_IPV4 = [
        ['0.0.0.0', 8],          // "this network" / unspecified
        ['10.0.0.0', 8],         // RFC1918
        ['100.64.0.0', 10],      // CGNAT
        ['127.0.0.0', 8],        // loopback
        ['169.254.0.0', 16],     // link-local incl. 169.254.169.254
        ['172.16.0.0', 12],      // RFC1918
        ['192.0.0.0', 24],       // IETF protocol assignments
        ['192.0.2.0', 24],       // TEST-NET-1
        ['192.168.0.0', 16],     // RFC1918
        ['198.18.0.0', 15],      // benchmarking
        ['198.51.100.0', 24],    // TEST-NET-2
        ['203.0.113.0', 24],     // TEST-NET-3
        ['224.0.0.0', 4],        // multicast
        ['240.0.0.0', 4],        // reserved
        ['255.255.255.255', 32], // limited broadcast
    ];

    /** @var list<string> docker-compose service names (single-label) */
    private const DOCKER_SERVICE_NAMES = [
        'db',
        'auth-service',
        'work-scope-rag',
        'inquiry-handler',
        'dashboard',
    ];

    /** @var list<string> suffixes that always mean "internal" */
    private const INTERNAL_HOST_SUFFIXES = [
        '.local',
        '.internal',
        '.localhost',
    ];

    /** @var callable(string): list<string>|null */
    private $resolver;

    /**
     * @param  callable(string): list<string>|null|null  $resolver
     *         host => resolved IP strings (A + AAAA). When null the default
     *         resolver (gethostbynamel + dns_get_record) is used.
     */
    public function __construct(?callable $resolver = null)
    {
        $this->resolver = $resolver ?? static function (string $host): array {
            $ips = gethostbynamel($host) ?: [];

            $aaaa = @dns_get_record($host, DNS_AAAA);

            if (is_array($aaaa)) {
                foreach ($aaaa as $record) {
                    if (isset($record['ipv6'])) {
                        $ips[] = $record['ipv6'];
                    }
                }
            }

            return $ips;
        };
    }

    /**
     * Validate a candidate target URL.
     *
     * @return array{host: string, port: int, ip: string}|null
     *         The validated target: host, the exact port (always the scheme
     *         default), and the single PUBLIC IP the caller must pin the
     *         connection to (this is what defeats DNS rebinding). Returns
     *         `null` when ANY check fails — FAIL CLOSED.
     */
    public function validate(string $url): ?array
    {
        $split = $this->splitUrl($url);

        if ($split === null) {
            return null;
        }

        [$host, $port] = $split;

        if (! $this->isAllowedHostname($host)) {
            return null;
        }

        $addresses = $this->resolveAddresses($host);

        if ($addresses === []) {
            return null;
        }

        foreach ($addresses as $ip) {
            if (! $this->isPublicAddress($ip)) {
                return null;
            }
        }

        // Pin to one address; prefer IPv4 (fewer IPv6-path surprises).
        $pin = $this->pickPinnedAddress($addresses);

        if ($pin === null) {
            return null;
        }

        return ['host' => $host, 'port' => $port, 'ip' => $pin];
    }

    /**
     * @return array{0: string, 1: int}|null  [host, port]
     */
    private function splitUrl(string $url): ?array
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower((string) $parts['scheme']);

        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $defaultPort = $scheme === 'https' ? 443 : 80;
        $port        = (int) ($parts['port'] ?? $defaultPort);

        // Only the scheme default ports (80/443) are ever allowed.
        if ($port !== $defaultPort) {
            return null;
        }

        $host = strtolower(trim((string) $parts['host'], " \t\n\r\0\x0B[]."));

        if ($host === '' || strlen($host) > 253) {
            return null;
        }

        return [$host, $port];
    }

    private function isAllowedHostname(string $host): bool
    {
        if ($this->isIpLiteral($host)) {
            // Literals are refused up front: leaf URLs use hostnames, so
            // there is no legitimate reason to ever see a raw IP.
            return false;
        }

        // Single-label names are never safe: localhost, db, auth-service,
        // work-scope-rag, inquiry-handler, dashboard, ...
        if (! str_contains($host, '.')) {
            return false;
        }

        if (in_array($host, self::DOCKER_SERVICE_NAMES, true)) {
            return false;
        }

        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            return false;
        }

        foreach (self::INTERNAL_HOST_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return false;
            }
        }

        // Defence in depth against accidentally-blocklisted service names
        // appearing as a label (e.g. "inquiry-handler.example").
        foreach (self::DOCKER_SERVICE_NAMES as $name) {
            if (preg_match('/(^|\.)' . preg_quote($name, '/') . '(\.|$)/', $host) === 1) {
                return false;
            }
        }

        return true;
    }

    private function isIpLiteral(string $host): bool
    {
        return filter_var($host, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * @return list<string>
     */
    private function resolveAddresses(string $host): array
    {
        try {
            $ipv4 = gethostbynamel($host) ?: [];
            $ipv6 = [];

            $a6 = @dns_get_record($host, DNS_AAAA);

            if (is_array($a6)) {
                foreach ($a6 as $record) {
                    if (isset($record['ipv6'])) {
                        $ipv6[] = $record['ipv6'];
                    }
                }
            }
        } catch (Throwable) {
            return [];
        }

        return array_values(array_unique(array_merge($ipv4, $ipv6)));
    }

    private function isPublicAddress(string $ip): bool
    {
        // IPv4-mapped IPv6 «::ffff:a.b.c.d» — check the EMBEDDED IPv4.
        if (preg_match('/^::ffff:(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})$/i', $ip, $m)) {
            return $this->isPublicIpv4($m[1]);
        }

        // Also catch the alt mapped form «::ffff:0:0/96» with a hex tail.
        if (preg_match('/^::ffff:([0-9a-f]{1,4}):([0-9a-f]{1,4})$/i', $ip, $m)) {
            $v4 = (string) hexdec($m[1][0] . $m[1][1] ?: '0') . '.' // placeholder (unreachable)
                . (string) hexdec($m[2]);

            return false; // hex-mapped forms are refused regardless
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $this->isPublicIpv4($ip);
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return $this->isPublicIpv6($ip);
        }

        return false;
    }

    private function isPublicIpv4(string $ip): bool
    {
        $long = (int) ip2long($ip);

        if ($long === -1) {
            return false;
        }

        foreach (self::PRIVATE_IPV4 as [$base, $bits]) {
            $baseLong = (int) ip2long($base);
            $mask     = $bits === 0 ? 0 : (0xFFFFFFFF << (32 - $bits)) & 0xFFFFFFFF;

            if (($long & $mask) === ($baseLong & $mask)) {
                return false;
            }
        }

        return true;
    }

    private function isPublicIpv6(string $ip): bool
    {
        $packed = @inet_pton($ip);

        if ($packed === false) {
            return false;
        }

        $bytes = array_values(unpack('C16', $packed) ?: []);

        $inSubnet = static function (string $subnet, int $bits) use ($bytes): bool {
            $net = array_values(unpack('C16', @inet_pton($subnet) ?: ($subnet === '::' ? str_repeat("\0", 16) : str_repeat("\0", 16))) ?: []);
            $fullUnits = intdiv($bits, 8);

            for ($i = 0; $i < $fullUnits; $i++) {
                if ($bytes[$i] !== ($net[$i] ?? 0)) {
                    return false;
                }
            }

            $rem = $bits % 8;
            if ($rem > 0) {
                $byteMask = (0xFF << (8 - $rem)) & 0xFF;
                if (($bytes[$fullUnits] & $byteMask) !== (($net[$fullUnits] ?? 0) & $byteMask)) {
                    return false;
                }
            }

            return true;
        };

        return ! (
            $inSubnet('::', 128)              // unspecified
            || $inSubnet('::1', 128)          // loopback
            || $inSubnet('fc00::', 7)         // ULA
            || $inSubnet('fe80::', 10)        // link-local
            || $inSubnet('ff00::', 8)         // multicast
            || $inSubnet('2001:db8::', 32)    // documentation
        );
    }

    /**
     * @param  list<string>  $addresses
     */
    private function pickPinnedAddress(array $addresses): ?string
    {
        foreach ($addresses as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return $ip;
            }
        }

        return $addresses[0] ?? null;
    }
}
