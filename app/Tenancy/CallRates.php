<?php

namespace App\Tenancy;

/**
 * Hosted service: what a minute to a destination costs us (Twilio's published
 * rate card, resources/call-rates/outbound.php, refreshed by bin/call-rates)
 * and what we charge: that cost x tenancy.call_charges.markup, in pounds.
 * Rates are found by the longest matching prefix.
 */
final class CallRates
{
    /** @var array<string, float>|null */
    private static ?array $table = null;

    /** Forgets the loaded table (tests change the file or the settings). */
    public static function flush(): void
    {
        self::$table = null;
    }

    /** Twilio's cost in USD a minute to call this number (+E.164), or null if the card has no rate for it. */
    public static function costUsd(string $e164): ?float
    {
        $digits = ltrim($e164, '+');
        $table = self::table();
        for ($n = min(strlen($digits), 15); $n > 0; $n--) {
            if (isset($table[$prefix = substr($digits, 0, $n)])) {
                return $table[$prefix];
            }
        }

        return null;
    }

    /** What we charge, in pence a minute, to call this number; null if it can't be priced. */
    public static function pence(string $e164): ?float
    {
        return self::lookup($e164)['pence'] ?? null;
    }

    /**
     * The rate that applies to a number and the prefix it came from. Our own
     * rates (tenancy.call_charges.other_pence, prefix => pence) win over the
     * rate card when they are at least as specific.
     *
     * @return array{prefix: string, pence: float}|null
     */
    public static function lookup(string $e164): ?array
    {
        $own = null;
        foreach ((array) config('tenancy.call_charges.other_pence') as $prefix => $pence) {
            if (str_starts_with($e164, (string) $prefix) && ($own === null || strlen((string) $prefix) > strlen($own['prefix']))) {
                $own = ['prefix' => (string) $prefix, 'pence' => (float) $pence];
            }
        }

        $digits = ltrim($e164, '+');
        $table = self::table();
        for ($n = min(strlen($digits), 15); $n > 0; $n--) {
            if (isset($table[$prefix = substr($digits, 0, $n)])) {
                // A rate of our own at least as specific as the card's wins.
                return $own !== null && strlen($own['prefix']) - 1 >= $n ? $own : ['prefix' => '+'.$prefix, 'pence' => self::priceUsd($table[$prefix])];
            }
        }

        return $own;
    }

    /** What an incoming UK minute costs us in pence, with the markup. */
    public static function inboundPence(): float
    {
        return self::priceUsd((float) config('tenancy.call_charges.inbound_usd'));
    }

    /**
     * Typical UK extra-minute prices in pence, for the price pages.
     *
     * @return array{landline: ?float, mobile: ?float, inbound: float}
     */
    public static function ukExamples(): array
    {
        return ['landline' => self::pence('+442079460000'), 'mobile' => self::pence('+447511123456'), 'inbound' => self::inboundPence()];
    }

    /** Pence for display: 1.35p, 17.8p, £1.19. */
    public static function format(float $pence): string
    {
        return match (true) {
            $pence >= 100 => '£'.number_format($pence / 100, 2),
            $pence >= 10 => number_format($pence, 1).'p',
            default => number_format($pence, 2).'p',
        };
    }

    /** A cost in USD with our markup, in pence. */
    public static function priceUsd(float $usd): float
    {
        return $usd * (float) config('tenancy.call_charges.usd_to_gbp') * 100 * (float) config('tenancy.call_charges.markup');
    }

    /** @return array<string, float> */
    private static function table(): array
    {
        if (self::$table === null) {
            $file = (string) config('tenancy.call_charges.rates_file');
            self::$table = is_file($file) ? (array) require $file : [];
        }

        return self::$table;
    }
}
