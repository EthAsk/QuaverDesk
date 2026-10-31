<?php

namespace App\Voice;

use App\Models\DeskPhone;
use App\Tenancy\Tenancy;
use Illuminate\Support\Facades\Cache;

/**
 * Turns a desk phone's lock (addresses, ranges and host names) into the CIDRs
 * the voice server checks. Host names (an office's dynamic DNS name, say) are
 * looked up here, and again every few minutes (phones:refresh-locks), because
 * the voice server only understands addresses.
 *
 * Fails closed: if a name can't be looked up, the last addresses it had are
 * used; a name that has never resolved lets nothing in.
 */
class PhoneLocks
{
    /** Matches no phone: used when nothing in a lock resolves. */
    public const NOWHERE = '0.0.0.0/32';

    public function cidrs(string $lock): string
    {
        $cidrs = [];
        foreach (array_filter(explode(',', $lock)) as $entry) {
            array_push($cidrs, ...(str_contains($entry, '/') ? [$entry] : $this->host($entry)));
        }

        return $cidrs === [] ? self::NOWHERE : implode(',', array_unique($cidrs));
    }

    /**
     * Looks up every host name in every company's locks. True if any now has
     * different addresses, so the voice server needs its settings again.
     */
    public function refresh(): bool
    {
        $hosts = app(Tenancy::class)->everywhere(fn () => DeskPhone::query()->whereNotNull('allowed_ips')->pluck('allowed_ips'))
            ->flatMap(fn (string $lock) => explode(',', $lock))
            ->reject(fn (string $entry) => str_contains($entry, '/'))
            ->unique();

        $changed = false;
        foreach ($hosts as $host) {
            $before = Cache::get(self::key($host));
            $changed = $this->host($host) !== $before || $changed;
        }

        return $changed;
    }

    /** @return list<string> */
    private function host(string $host): array
    {
        $found = $this->lookup($host);
        if ($found !== []) {
            sort($found);
            Cache::forever(self::key($host), $found);

            return $found;
        }

        return Cache::get(self::key($host), []);
    }

    /** A and AAAA records as /32 and /128. */
    protected function lookup(string $host): array
    {
        $out = [];
        foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $r) {
            if (isset($r['ip'])) {
                $out[] = $r['ip'].'/32';
            } elseif (isset($r['ipv6'])) {
                $out[] = inet_ntop(inet_pton($r['ipv6'])).'/128';
            }
        }

        return array_values(array_unique($out));
    }

    private static function key(string $host): string
    {
        return 'phone-lock-host:'.strtolower($host);
    }
}
