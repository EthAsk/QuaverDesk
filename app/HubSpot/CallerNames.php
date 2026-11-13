<?php

namespace App\HubSpot;

use App\Domain\Licensing\Feature;
use App\Support\LicenceManager;
use App\Tenancy\Tenancy;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Who is calling, according to the company's HubSpot, so phones show a name and
 * not just a number. A caller is waiting, so this never holds a call up:
 * answers are remembered (6 hours; 15 minutes for "not in HubSpot"), a lookup
 * gives up after 2 seconds, and after a failure none is tried for 2 minutes.
 */
class CallerNames
{
    public function __construct(private readonly HubSpotSettings $settings, private readonly LicenceManager $licences, private readonly Tenancy $tenancy)
    {
    }

    public function lookup(?string $number): ?string
    {
        if ($number === null || ! preg_match('/^\+\d{7,15}$/', $number) || ! $this->licences->allows(Feature::HubSpot)) {
            return null;
        }
        $options = $this->settings->all();
        if ($options['token'] === null || ! $options['log_calls'] || ! $options['lookup_names']) {
            return null;
        }

        $company = $this->tenancy->id();
        $key = "hubspot-name:{$company}:".hash('sha256', $number);
        $down = "hubspot-down:{$company}";
        if (($known = Cache::get($key)) !== null) {
            return $known === '' ? null : $known;
        }
        if (Cache::has($down)) {
            return null;
        }

        try {
            $name = (new HubSpotClient($options['token']))->quickly()->contactName($number);
        } catch (Throwable) {
            Cache::put($down, true, 120);

            return null;
        }
        Cache::put($key, $name ?? '', $name === null ? 900 : 21600);

        return $name;
    }
}
