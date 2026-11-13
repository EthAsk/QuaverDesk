<?php

namespace Tests\Support;

/**
 * An optional module's records for the company-separation tests: a class at
 * modules/<Name>/Tests/<Name>TenantFixtures.php, called inside another company,
 * so the tests try the module's routes against records that aren't yours.
 */
interface TenantFixtures
{
    /**
     * Creates one of each record, inside the current company.
     *
     * @param  array<string, int>  $core  the ids the core test already made (user, call, …)
     * @return array<string, int>  route parameter name => record id
     */
    public static function create(array $core): array;

    /** @return list<class-string<\Illuminate\Database\Eloquent\Model>> models whose rows must not change */
    public static function models(): array;
}
