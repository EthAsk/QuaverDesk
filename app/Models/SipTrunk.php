<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use App\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SipTrunk extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'name', 'provider', 'inbound_ips', 'origination_uris', 'auth_username', 'auth_password', 'register',
        'status', 'last_error', 'default_outbound', 'secure',
    ];

    protected $hidden = ['auth_password', 'gateway_token'];

    protected static function booted(): void
    {
        static::creating(fn (SipTrunk $trunk) => $trunk->gateway_token ??= bin2hex(random_bytes(6)));
    }

    protected function casts(): array
    {
        return [
            'inbound_ips' => 'array',
            'origination_uris' => 'array',
            'auth_password' => 'encrypted',
            'register' => 'boolean',
            'secure' => 'boolean',
            'default_outbound' => 'boolean',
            // Set only by the operator (not fillable): calls on it are ours to bill.
            'supplied_by_operator' => 'boolean',
        ];
    }

    public function numbers(): HasMany
    {
        return $this->hasMany(PhoneNumber::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * One voice server gateway per provider address, tried in this order. The
     * name ends in a secret: a registering gateway gives the provider the
     * address gw+<name>, so a call to that address can only come from the
     * provider this trunk registered with.
     *
     * @return list<string>
     */
    public function gatewayNames(): array
    {
        return array_map(fn (int $i) => "qd-t{$this->id}-".($i + 1).'-'.$this->gateway_token, array_keys(array_values($this->origination_uris ?? [])));
    }

    /**
     * On the hosted service, the trunk's own host name (<label>.<base domain>),
     * ending in the same secret: providers that can't register send calls there,
     * so a call to it can only come from the provider this trunk was given to.
     */
    public function addressLabel(): string
    {
        return "qd-t{$this->id}-{$this->gateway_token}";
    }

    /** Where the provider sends this trunk's calls (null when VOICE_TRUNK_ADDRESS isn't set). */
    public function inboundAddress(): ?string
    {
        $server = preg_replace('/^sips?:/i', '', (string) config('voip.freeswitch.trunk_address'));
        if ($server === '') {
            return null;
        }

        [$host, $port] = preg_match('/^(.+?)(?::(\d+))?$/', $server, $m) ? [$m[1], $m[2] ?? ''] : [$server, ''];
        if (app(Tenancy::class)->enabled()) {
            $host = $this->addressLabel().'.'.config('tenancy.base_domain');
        }
        if ($this->secure) {
            return 'sip:'.$host.':'.config('voip.freeswitch.trunk_tls_port').';transport=tls';
        }

        return 'sip:'.$host.($port !== '' ? ':'.$port : '');
    }

    /** Whether the provider is told where to send calls by this trunk's registration. */
    public function registers(): bool
    {
        return $this->register && $this->auth_username;
    }

    /** Gateways for dialling out, as a DialTarget trunk value ("qd-t1-1-…|qd-t1-2-…"). */
    public function dialTrunk(): string
    {
        return implode('|', $this->gatewayNames());
    }

    public static function defaultOutbound(): ?self
    {
        return static::query()->where('default_outbound', true)->where('status', 'active')->first();
    }
}
