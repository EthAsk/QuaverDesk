<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Passkeys\Contracts\PasskeyUser;
use Laravel\Passkeys\PasskeyAuthenticatable;

class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use BelongsToTenant, HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    public const ROLE_OWNER = 'owner';
    public const ROLE_ADMIN = 'admin';
    public const ROLE_USER = 'user';

    public const ROLES = [self::ROLE_OWNER, self::ROLE_ADMIN, self::ROLE_USER];

    // Follow-me: also ring the person's own mobile.
    public const FOLLOW_ME_OFF = 'off';
    public const FOLLOW_ME_TOGETHER = 'together';
    public const FOLLOW_ME_AFTER = 'after';

    // `role` is deliberately NOT fillable: it is only ever set explicitly.
    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'sip_secret',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'mobile_verified_at' => 'datetime',
            'follow_me_delay' => 'integer',
            'two_factor_confirmed_at' => 'datetime',
            'password' => 'hashed',
            'sip_secret' => 'encrypted',
        ];
    }

    public function isAdmin(): bool
    {
        return in_array($this->role, [self::ROLE_OWNER, self::ROLE_ADMIN], true);
    }

    public function isOwner(): bool
    {
        return $this->role === self::ROLE_OWNER;
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    protected static function booted(): void
    {
        // Everyone gets an extension; admins can change it under Users.
        static::creating(fn (User $user) => $user->extension ??= self::nextExtension());
    }

    /** The lowest free extension from 201 up. */
    public static function nextExtension(): string
    {
        $taken = array_flip([
            ...static::query()->whereNotNull('extension')->pluck('extension')->all(),
            ...RingGroup::query()->whereNotNull('extension')->pluck('extension')->all(),
        ]);
        for ($n = 201; isset($taken[(string) $n]); $n++);

        return (string) $n;
    }

    /** What colleagues dial: their extension, or the softphone's SIP username if they have none. */
    public function dialable(): string
    {
        return $this->extension ?: $this->carrierIdentity();
    }

    /** Calls to this person also ring their mobile, which they have proved is theirs. */
    public function followsMe(): bool
    {
        return $this->follow_me !== self::FOLLOW_ME_OFF && $this->mobile !== null && $this->mobile_verified_at !== null;
    }

    /** The SIP username of this user's softphone on the voice server (never changes, unlike the extension). */
    public function carrierIdentity(): string
    {
        return 'user-'.$this->getKey();
    }

    /** The softphone's SIP password, created on first use. */
    public function sipSecret(): string
    {
        if (! $this->sip_secret) {
            $this->forceFill(['sip_secret' => bin2hex(random_bytes(24))])->saveQuietly();
        }

        return $this->sip_secret;
    }

    /**
     * A new softphone password: whoever had the old one (a stolen session, an old
     * device) can no longer use the phone. The person's own phone fetches the new one.
     */
    public function rotateSipSecret(): void
    {
        $this->forceFill(['sip_secret' => bin2hex(random_bytes(24))])->saveQuietly();
    }

    public function calls(): HasMany
    {
        return $this->hasMany(Call::class);
    }

    public function deskPhones(): HasMany
    {
        return $this->hasMany(DeskPhone::class);
    }

    /** Where this person's unanswered calls go. */
    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class);
    }
}
