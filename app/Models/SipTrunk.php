<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SipTrunk extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'name', 'provider', 'inbound_ips', 'origination_uris', 'auth_username', 'auth_password', 'register',
        'status', 'last_error', 'default_outbound',
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
