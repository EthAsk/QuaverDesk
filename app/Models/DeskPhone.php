<?php

namespace App\Models;

use App\Http\Controllers\Admin\DeskPhoneController;
use App\Tenancy\BelongsToTenant;
use App\Voice\PhoneLocks;
use App\Voice\VoiceServer;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeskPhone extends Model
{
    use BelongsToTenant;

    /** Handsets we can set up automatically, by the files they ask for (see PhoneProvisioning). */
    public const BRANDS = ['yealink' => 'Yealink', 'grandstream' => 'Grandstream (GRP)', 'fanvil' => 'Fanvil (X series)'];

    protected $fillable = ['user_id', 'label', 'sip_username', 'sip_password', 'allowed_ips', 'filter_lan', 'brand', 'mac'];

    protected $hidden = ['sip_password', 'admin_password'];

    protected function casts(): array
    {
        return [
            'sip_password' => 'encrypted',
            'admin_password' => 'encrypted',
            'provisioned_at' => 'datetime',
            'setup_until' => 'datetime',
            'waiting_at' => 'datetime',
            'filter_lan' => 'boolean',
            'blf_keys' => 'array',
        ];
    }

    /** How long a set-up window stays open: the phone's first download in it is accepted and its address trusted. */
    public const SETUP_HOURS = 24;

    public function setupOpen(): bool
    {
        return $this->setup_until !== null && $this->setup_until->isFuture();
    }

    /** Opens the set-up window (when the phone is added or its make or MAC changes, or an admin asks). */
    public function openSetup(): static
    {
        return $this->forceFill(['setup_until' => now()->addHours(self::SETUP_HOURS), 'waiting_ip' => null, 'waiting_agent' => null, 'waiting_at' => null]);
    }

    /** May a settings download from $ip go ahead without an admin approving it? */
    public function acceptsDownloadFrom(string $ip): bool
    {
        return $this->setupOpen() || ($this->trusted_ip !== null && hash_equals($this->trusted_ip, $ip));
    }

    /** "805EC0123456" or "80:5e:c0:12:34:56" as "805ec0123456"; null if it isn't a MAC address. */
    public static function normaliseMac(string $input): ?string
    {
        $mac = strtolower(preg_replace('/[\s:.-]/', '', $input) ?? '');

        return preg_match('/^[0-9a-f]{12}$/', $mac) && $mac !== '000000000000' ? $mac : null;
    }

    /** "80:5E:C0:12:34:56" */
    public function macLabel(): string
    {
        return strtoupper(implode(':', str_split((string) $this->mac, 2)));
    }

    public function provisioned(): bool
    {
        return $this->brand !== null && $this->mac !== null;
    }

    /** A new password for the handset's own web page, sent at its next download. */
    public function newAdminPassword(): string
    {
        $this->forceFill(['admin_password' => DeskPhoneController::strongPassword()]);

        return $this->admin_password;
    }

    /**
     * Local (private) networks. A locked phone may sign in from these too unless
     * its lock filters them: they only ever reach the server from its own
     * network, never from the internet, so they let in phones in the same
     * building as the server, which it sees by their local address.
     */
    public const LAN = ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', 'fc00::/7'];

    /** The IP addresses (CIDRs) this phone may sign in and download its settings from, or null for anywhere. */
    public function lockCidrs(): ?array
    {
        if (! $this->allowed_ips) {
            return null;
        }
        $cidrs = explode(',', app(PhoneLocks::class)->cidrs($this->allowed_ips));

        return $this->filter_lan ? $cidrs : array_values(array_unique([...$cidrs, ...self::LAN]));
    }

    /** Most busy lamp keys a phone is given (after its own line key). */
    public const MAX_BLF_KEYS = 20;

    /**
     * The colleagues this phone's busy lamp keys watch, in key order: people
     * since removed or made forward-only are skipped. Empty when none are set.
     *
     * @return Collection<int, User>
     */
    public function blfColleagues(): Collection
    {
        $ids = array_map('intval', $this->blf_keys ?? []);
        $people = User::query()->seated()->whereKey($ids)->get(['id', 'name', 'extension'])->keyBy('id');

        return collect($ids)->map(fn (int $id) => $people->get($id))->filter()->values();
    }

    public function events(): HasMany
    {
        return $this->hasMany(DeskPhoneEvent::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The desk phone service: the voice server's SIP domain, or null if not configured. */
    public static function service(): ?array
    {
        $domain = app(VoiceServer::class)->domain();

        return $domain ? ['domain' => $domain] : null;
    }

    /** Most addresses, ranges or host names a phone can be locked to. */
    public const MAX_ALLOWED_IPS = 5;

    /**
     * "203.0.113.7, 198.51.100.0/24, office.example.com" stored as
     * "203.0.113.7/32,198.51.100.0/24,office.example.com" (host names are looked
     * up by PhoneLocks), '' for blank (anywhere), or null if any part isn't an
     * IPv4/IPv6 address, a range or a host name.
     */
    public static function normaliseAllowedIps(string $input): ?string
    {
        $parts = array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', $input) ?: [])));
        if (count($parts) > self::MAX_ALLOWED_IPS) {
            return null;
        }

        $cidrs = [];
        foreach ($parts as $part) {
            $host = strtolower(rtrim($part, '.'));
            if (preg_match('/^(?=.{4,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host)) {
                $cidrs[] = $host;

                continue;
            }
            [$ip, $bits] = array_pad(explode('/', $part, 2), 2, null);
            $v6 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
            if (! $v6 && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                return null;
            }
            $max = $v6 ? 128 : 32;
            if ($bits !== null && (! ctype_digit($bits) || (int) $bits < ($v6 ? 32 : 8) || (int) $bits > $max)) {
                return null; // a range wider than /8 (or /32 for IPv6) isn't a lock
            }
            $cidrs[] = inet_ntop(inet_pton($ip)).'/'.($bits ?? $max);
        }

        return implode(',', array_unique($cidrs));
    }

    /** For people: "203.0.113.7, 198.51.100.0/24". */
    public function allowedIpsLabel(): string
    {
        return implode(', ', array_map(fn ($c) => preg_replace('#/(32|128)$#', '', $c), explode(',', (string) $this->allowed_ips)));
    }

    public function sipUri(): ?string
    {
        $service = self::service();

        return $service ? 'sip:'.$this->sip_username.'@'.$service['domain'] : null;
    }
}
