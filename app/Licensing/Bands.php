<?php

namespace App\Licensing;

use App\Domain\Licensing\Licence;
use App\Domain\Licensing\Plan;

/**
 * Licences are sold in site-size bands (up to 5, 10, 25, 50, 100 users), each
 * with a monthly and an annual price (config/licensing.php). A key's seats are
 * the top of its band, so a site moves up a band when it adds its next user.
 */
final class Bands
{
    public const INTERVALS = ['month', 'year'];

    /** @return list<int> */
    public static function all(): array
    {
        return array_map('intval', config('licensing.bands'));
    }

    /** The smallest band that fits this many users; null when it needs a quote. */
    public static function fitting(int $users): ?int
    {
        foreach (self::all() as $band) {
            if ($users <= $band) {
                return $band;
            }
        }

        return null;
    }

    /** The band above $seats; null at the top. */
    public static function next(int $seats): ?int
    {
        return self::fitting($seats + 1);
    }

    /** "6–10" for the 10 band. */
    public static function label(int $band): string
    {
        $index = array_search($band, self::all(), true);
        $from = $index ? self::all()[$index - 1] + 1 : 1;

        return $from === 1 ? "Up to {$band}" : "{$from}–{$band}";
    }

    /** GBP, the full price, for one month or one year. */
    public static function price(Plan $plan, int $band, string $interval = 'month'): int
    {
        $monthly = (int) config("licensing.monthly_gbp.{$plan->value}.{$band}");

        return $interval === 'year' ? $monthly * (int) config('licensing.annual_months') : $monthly;
    }

    /**
     * The next band this install could move up to from its admin pages: a paid,
     * renewing licence below the top band, with a licence server to ask.
     *
     * @return array{band: int, label: string, monthly: int}|null
     */
    public static function upgradeOffer(Licence $licence): ?array
    {
        if ($licence->isFree() || $licence->expiresAt === null || ! config('licensing.server_url') || config('tenancy.enabled')) {
            return null;
        }
        $next = self::next($licence->seats);

        return $next === null ? null : ['band' => $next, 'label' => self::label($next), 'monthly' => self::price($licence->plan, $next)];
    }
}
