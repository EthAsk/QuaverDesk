<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use App\Voice\VoiceServer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeskPhone extends Model
{
    use BelongsToTenant;

    protected $fillable = ['user_id', 'label', 'sip_username', 'sip_password'];

    protected $hidden = ['sip_password'];

    protected function casts(): array
    {
        return ['sip_password' => 'encrypted'];
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

    public function sipUri(): ?string
    {
        $service = self::service();

        return $service ? 'sip:'.$this->sip_username.'@'.$service['domain'] : null;
    }
}
