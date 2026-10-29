<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use App\Voice\VoiceUrl;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Audio the business uploaded or recorded, stored as a 16 kHz mono WAV under a
 * random name. The voice server fetches it from route('voice.sound').
 */
class Sound extends Model
{
    use BelongsToTenant;

    protected $fillable = ['name', 'file', 'seconds'];

    protected function casts(): array
    {
        return ['seconds' => 'float'];
    }

    protected static function booted(): void
    {
        static::deleted(fn (Sound $sound) => @unlink($sound->path()));
    }

    public static function pathFor(string $file): string
    {
        return storage_path('app/sounds/'.$file.'.wav');
    }

    public function path(): string
    {
        return self::pathFor($this->file);
    }

    public function url(): string
    {
        return VoiceUrl::to('voice.sound', ['file' => $this->file]);
    }

    /** "45 seconds" or "3:05" */
    public function length(): string
    {
        $s = (int) round($this->seconds);

        return $s >= 60 ? sprintf('%d:%02d', intdiv($s, 60), $s % 60) : $s.' seconds';
    }

    public function mailboxes(): HasMany
    {
        return $this->hasMany(Mailbox::class, 'greeting_sound_id');
    }

    public function ivrMenus(): HasMany
    {
        return $this->hasMany(IvrMenu::class, 'greeting_sound_id');
    }

    /** The sound callers hear on hold, if one is chosen. */
    public static function holdMusic(): ?self
    {
        $id = Setting::get('sounds.hold_music');

        return $id ? static::query()->find($id) : null;
    }
}
