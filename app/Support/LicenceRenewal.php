<?php

namespace App\Support;

use App\Domain\Licensing\InvalidLicence;
use App\Domain\Licensing\Licence;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Asks the licensor's server for a renewed key. Sends only the current key.
 * Any key received is verified locally before it is installed, so a
 * compromised or spoofed server can never grant a plan it couldn't sign.
 */
class LicenceRenewal
{
    public function __construct(private readonly LicenceManager $licences)
    {
    }

    public function enabled(): bool
    {
        return (bool) config('licensing.server_url');
    }

    /**
     * Moves this install up to $band users through the licence server, which
     * charges the subscription's card, and installs the new key it returns.
     *
     * @throws RuntimeException with a message for the admin; nothing changes
     */
    public function upgrade(int $band): Licence
    {
        $url = rtrim((string) config('licensing.server_url'), '/');
        $key = Setting::get('licence_key');
        if (! str_starts_with($url, 'https://') || ! $key) {
            throw new RuntimeException('This install can\'t reach the licence server to upgrade.');
        }

        try {
            $response = Http::acceptJson()->timeout(30)->post($url.'/api/licence/upgrade', ['key' => $key, 'band' => $band]);
        } catch (Throwable $e) {
            throw new RuntimeException('Could not reach the licence server: '.$e->getMessage());
        }

        $newKey = $response->json('key');
        if ($response->failed() || ! is_string($newKey) || $newKey === '') {
            throw new RuntimeException((string) ($response->json('message') ?? 'The licence server said HTTP '.$response->status().'.'));
        }

        try {
            return $this->licences->install($newKey);
        } catch (InvalidLicence $e) {
            throw new RuntimeException('The licence server sent a key that failed verification: '.$e->getMessage());
        }
    }

    /** @return string a human-readable result */
    public function check(): string
    {
        $url = rtrim((string) config('licensing.server_url'), '/');
        $key = Setting::get('licence_key');

        if ($url === '') {
            return 'Automatic renewal is off (no LICENCE_SERVER_URL set).';
        }
        if (! $key) {
            return 'No licence key installed.';
        }
        if (! str_starts_with($url, 'https://')) {
            return 'LICENCE_SERVER_URL must use https.';
        }

        try {
            $response = Http::acceptJson()->timeout(15)->post($url.'/api/licence/refresh', ['key' => $key]);
        } catch (Throwable $e) {
            return 'Could not reach the licence server: '.$e->getMessage();
        }

        if ($response->status() === 410) {
            return 'Your subscription has been cancelled. The current key keeps working until it expires.';
        }
        if ($response->failed()) {
            return 'The licence server said: '.($response->json('message') ?? 'HTTP '.$response->status());
        }

        $newKey = $response->json('key');
        if (! is_string($newKey) || $newKey === '') {
            return (string) ($response->json('message') ?? 'Your key is already the latest.');
        }

        try {
            $licence = $this->licences->install($newKey);
        } catch (InvalidLicence $e) {
            return 'The licence server sent a key that failed verification: '.$e->getMessage();
        }

        return "Renewed: {$licence->plan->label()} for {$licence->licensee}"
            .($licence->expiresAt ? ', valid until '.$licence->expiresAt->format('j M Y') : '').'.';
    }
}
