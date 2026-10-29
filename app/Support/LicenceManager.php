<?php

namespace App\Support;

use App\Domain\Licensing\Feature;
use App\Domain\Licensing\InvalidLicence;
use App\Domain\Licensing\Licence;
use App\Domain\Licensing\LicenceCodec;
use App\Models\Setting;
use App\Tenancy\Tenancy;
use Illuminate\Support\Facades\Log;

/**
 * The single place the app asks "what is this install allowed to do?".
 * Any missing, invalid or expired key falls back to the Free plan. On the
 * hosted service the company's plan, set by the operator, is the licence and
 * keys are ignored.
 */
class LicenceManager
{
    private ?Licence $resolved = null;

    private ?string $problem = null;

    public function current(): Licence
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        if ($this->hosted()) {
            return $this->resolved = app(Tenancy::class)->current()->licence();
        }

        $key = Setting::get('licence_key');
        if (! $key) {
            return $this->resolved = Licence::free();
        }

        try {
            return $this->resolved = LicenceCodec::verify($key, (string) config('voip.licence_public_key'));
        } catch (InvalidLicence $e) {
            $this->problem = $e->getMessage();
            Log::warning('Licence key rejected; running as Free.', ['reason' => $e->getMessage()]);

            return $this->resolved = Licence::free();
        }
    }

    public function allows(Feature $feature): bool
    {
        return $this->current()->allows($feature);
    }

    /** Why the stored key was rejected, if it was. */
    public function problem(): ?string
    {
        $this->current();

        return $this->problem;
    }

    /** Whether the plan comes from the hosted service rather than a key. */
    public function hosted(): bool
    {
        return (bool) config('tenancy.enabled');
    }

    /** Verifies then stores a key. Throws InvalidLicence and stores nothing if it fails. */
    public function install(string $key): Licence
    {
        if ($this->hosted()) {
            throw new InvalidLicence('Licence keys are not used on the hosted service.');
        }
        $licence = LicenceCodec::verify($key, (string) config('voip.licence_public_key'));
        Setting::put('licence_key', trim($key));
        $this->reset();

        return $licence;
    }

    public function remove(): void
    {
        Setting::forget('licence_key');
        $this->reset();
    }

    private function reset(): void
    {
        $this->resolved = null;
        $this->problem = null;
    }
}
