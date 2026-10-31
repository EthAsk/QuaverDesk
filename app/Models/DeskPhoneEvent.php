<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The record of what happened to a company's desk phones: each settings
 * download (or refusal), and who showed or changed their passwords. Shown to
 * admins under Phones and apps and to the operator on the company's page.
 */
class DeskPhoneEvent extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    public const ADDED = 'added';
    public const REMOVED = 'removed';
    public const SETTINGS_SHOWN = 'settings_shown';
    public const LOCK_CHANGED = 'lock_changed';
    public const PROVISIONING_SET = 'provisioning_set';
    public const PROVISIONED = 'provisioned';
    public const REFUSED = 'refused';
    public const ADMIN_PASSWORD_SHOWN = 'admin_password_shown';
    public const ADMIN_PASSWORD_CHANGED = 'admin_password_changed';
    public const RESYNC_SENT = 'resync_sent';
    public const LOGIN_CHANGED = 'provisioning_login_changed';
    public const KEYS_CHANGED = 'blf_keys_changed';

    public const LABELS = [
        self::ADDED => 'Phone added',
        self::REMOVED => 'Phone removed',
        self::SETTINGS_SHOWN => 'SIP settings shown',
        self::LOCK_CHANGED => 'Sign-in lock changed',
        self::PROVISIONING_SET => 'Automatic set-up changed',
        self::PROVISIONED => 'Phone downloaded its settings',
        self::REFUSED => 'Settings download refused',
        self::ADMIN_PASSWORD_SHOWN => 'Admin password shown',
        self::ADMIN_PASSWORD_CHANGED => 'Admin password changed',
        self::RESYNC_SENT => 'Phone told to fetch its settings',
        self::LOGIN_CHANGED => 'Provisioning login changed',
        self::KEYS_CHANGED => 'Busy lamp keys changed',
    ];

    protected $fillable = ['desk_phone_id', 'user_id', 'kind', 'phone_label', 'ip', 'detail'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    /** Records an event. $by is the admin who did it; null for the phone itself. */
    public static function record(string $kind, ?DeskPhone $phone, ?User $by = null, ?string $detail = null, ?string $ip = null): self
    {
        return static::query()->create([
            'desk_phone_id' => $phone?->exists ? $phone->id : null,
            'phone_label' => $phone?->label,
            'user_id' => $by?->id,
            'kind' => $kind,
            'ip' => $ip ?? request()?->ip(),
            'detail' => $detail !== null ? mb_substr($detail, 0, 255) : null,
        ]);
    }

    public function label(): string
    {
        return self::LABELS[$this->kind] ?? $this->kind;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function phone(): BelongsTo
    {
        return $this->belongsTo(DeskPhone::class, 'desk_phone_id');
    }
}
