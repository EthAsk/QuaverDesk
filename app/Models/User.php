<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
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
            'forward_only' => 'boolean',
            'phone_only' => 'boolean',
            'internal_only' => 'boolean',
            'voicemail' => 'boolean',
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

    /**
     * No sign-in, softphone or desk phone: calls ring their mobile, or go to
     * their voicemail, which is emailed to them. Free: not counted against the plan.
     */
    public function isForwardOnly(): bool
    {
        return (bool) $this->forward_only;
    }

    /**
     * Desk phones only (a reception, lobby or hall phone, say): no sign-in or
     * softphone, so calls ring their desk phones. Counts as one of the plan's users.
     */
    public function isPhoneOnly(): bool
    {
        return (bool) $this->phone_only;
    }

    /** Can sign in to the web app (and so has a softphone): not forward-only or desk phones only. */
    public function canSignIn(): bool
    {
        return ! $this->isForwardOnly() && ! $this->isPhoneOnly() && ! $this->isSuspended();
    }

    /** Switched off by the company's directory (someone who left): can't sign in, but nothing is deleted. */
    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }

    /** Their phones may call colleagues (and 999/112) but no outside numbers. */
    public function isInternalOnly(): bool
    {
        return (bool) $this->internal_only;
    }

    /** Unanswered calls go to their voicemail box (when they have one). Off: the call ends. */
    public function hasVoicemail(): bool
    {
        return $this->voicemail !== false;
    }

    /** People who count against the plan's users (everyone but forward-only contacts). */
    public function scopeSeated(Builder $q): Builder
    {
        return $q->where('forward_only', false);
    }

    /**
     * The set-up key for someone who can't scan the QR code, in groups of four
     * ("ABCD EFGH …"). Only while two-factor is being set up, never once confirmed.
     */
    public function twoFactorSetupKey(): ?string
    {
        if ($this->two_factor_secret === null || $this->two_factor_confirmed_at !== null) {
            return null;
        }

        return trim(chunk_split(\Laravel\Fortify\Fortify::currentEncrypter()->decrypt($this->two_factor_secret), 4, ' '));
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
            ...CallQueue::extensions(),
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

    /**
     * The address colleagues' busy lamp (BLF) keys watch and dial for this
     * person, e.g. blf-3f9a…. Every company's phones share one SIP domain, so
     * it is a secret derived from the app key rather than the extension or
     * user id: only phones of this person's own company are given it.
     */
    public function blfId(): string
    {
        return 'blf-'.substr(hash_hmac('sha256', 'blf:'.$this->getKey(), (string) config('app.key')), 0, 20);
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

    /** The call queues they're an agent in (pivot logged_in: taking its calls now). */
    public function queues(): BelongsToMany
    {
        return $this->belongsToMany(CallQueue::class)->withPivot('logged_in');
    }

    /**
     * *45 and the dashboard button: logs them in to all their queues, or out
     * of them all when they're in every one already. True for logged in,
     * false for out, null when they aren't in any queue.
     */
    public function toggleQueues(): ?bool
    {
        $queues = $this->queues()->get();
        if ($queues->isEmpty()) {
            return null;
        }
        $in = $queues->contains(fn (CallQueue $q) => ! $q->pivot->logged_in);
        DB::table('call_queue_user')->where('user_id', $this->id)->update(['logged_in' => $in]);

        return $in;
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
