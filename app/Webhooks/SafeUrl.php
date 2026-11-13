<?php

namespace App\Webhooks;

/**
 * Where a webhook may be sent. https only, and never to an address that would
 * let a company reach into the server's own network: loopback, link-local
 * (cloud metadata) and reserved ranges are always refused. Private LAN
 * addresses are refused on the hosted service, where other companies' systems
 * live, and allowed on a self-hosted install (an automation tool on the LAN).
 *
 * The address checked is the address connected to (the caller pins it), so a
 * name that changes its answer between the check and the request gets nowhere.
 */
final class SafeUrl
{
    /** The IP address to send to, or null (with $why set) if this URL isn't allowed. */
    public static function resolve(string $url, ?string &$why = null): ?string
    {
        $parts = parse_url($url);
        if ($parts === false || strtolower($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            $why = 'Use an https:// address (without a username or password).';

            return null;
        }
        if (isset($parts['port']) && ! in_array((int) $parts['port'], [443, 8443], true)) {
            $why = 'Use the standard https port (443 or 8443).';

            return null;
        }

        $host = trim($parts['host'], '[]');
        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : self::lookup($host);
        if ($addresses === []) {
            $why = "Can't find '{$host}'. Check the address.";

            return null;
        }

        foreach ($addresses as $address) {
            if (! self::allowed($address)) {
                $why = 'That address is on a private or internal network, which webhooks can\'t be sent to.';

                return null;
            }
        }

        return $addresses[0];
    }

    public static function allowed(string $ip): bool
    {
        // ::ffff:10.0.0.1 is the IPv4 address 10.0.0.1.
        if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $ip, $m)) {
            $ip = $m[1];
        }

        $flags = FILTER_FLAG_NO_RES_RANGE | (config('tenancy.enabled') ? FILTER_FLAG_NO_PRIV_RANGE : 0);

        return filter_var($ip, FILTER_VALIDATE_IP, $flags) !== false
            && ! str_starts_with(strtolower($ip), 'fe80:')
            && ! str_starts_with($ip, '169.254.');
    }

    /** @return list<string> */
    private static function lookup(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];

        return array_values(array_filter(array_map(fn ($r) => $r['ip'] ?? $r['ipv6'] ?? null, $records)));
    }
}
