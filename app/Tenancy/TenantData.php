<?php

namespace App\Tenancy;

use App\Models\Acknowledgement;
use App\Models\Call;
use App\Models\DeskPhone;
use App\Models\IvrMenu;
use App\Models\Mailbox;
use App\Models\PhoneNumber;
use App\Models\Recording;
use App\Models\RingGroup;
use App\Models\Setting;
use App\Models\SipTrunk;
use App\Models\Sound;
use App\Models\Tenant;
use App\Models\User;
use App\Support\RecordingStore;
use App\Voice\Audio;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * A company's data as a whole: export it (UK GDPR access and portability, and
 * a per-company backup) or erase it (when they leave). Hosted service only.
 */
class TenantData
{
    /**
     * Everything a company owns, in an order that can be deleted from the top.
     *
     * @var list<class-string<Model>>
     */
    private const MODELS = [
        Recording::class, Call::class, Acknowledgement::class, DeskPhone::class, PhoneNumber::class,
        IvrMenu::class, RingGroup::class, Mailbox::class, Sound::class, SipTrunk::class, Setting::class, User::class,
    ];

    /** Never exported: passwords and keys that would let someone sign in or call as the company. */
    private const SECRET_SETTINGS = ['licence_key'];

    public function __construct(
        private readonly Tenancy $tenancy,
        private readonly RecordingStore $recordings,
    ) {
    }

    /** A zip of the company's records (JSON) and audio, readable without QuaverDesk. Returns its path. */
    public function export(Tenant $tenant): string
    {
        $dir = storage_path('app/exports');
        if (! is_dir($dir) && ! mkdir($dir, 0700, true)) {
            throw new RuntimeException("Cannot create {$dir}");
        }
        $path = $dir.'/'.$tenant->slug.'-'.now()->format('Ymd-His').'-'.bin2hex(random_bytes(3)).'.zip';

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new RuntimeException("Cannot create {$path}");
        }

        $this->tenancy->run($tenant, function () use ($zip, $tenant) {
            $zip->addFromString('README.txt', $this->readme($tenant));
            $zip->addFromString('company.json', self::json($tenant->only(['name', 'slug', 'domain', 'status', 'created_at'])));

            foreach (self::MODELS as $model) {
                $rows = $model::query()->orderBy('id')->get()
                    ->reject(fn (Model $m) => $m instanceof Setting && in_array($m->key, self::SECRET_SETTINGS, true))
                    ->map(fn (Model $m) => $this->exportable($m))
                    ->values();
                $zip->addFromString('data/'.(new $model())->getTable().'.json', self::json($rows));
            }

            $this->exportPivot($zip, 'ring_group_user', 'ring_group_id', RingGroup::class);
            $this->exportPivot($zip, 'mailbox_user', 'mailbox_id', Mailbox::class);

            foreach (Recording::query()->whereNotNull('path')->lazyById() as $recording) {
                if (($audio = $this->recordings->get($recording)) !== null) {
                    $zip->addFromString("audio/recordings/{$recording->id}.".Audio::typeOf($audio)[1], $audio);
                }
            }
            foreach (Sound::query()->lazyById() as $sound) {
                if (is_file($sound->path())) {
                    $zip->addFile($sound->path(), "audio/sounds/{$sound->id}.wav");
                }
            }
        });

        if (! $zip->close()) {
            throw new RuntimeException("Cannot write {$path}");
        }
        chmod($path, 0600);

        return $path;
    }

    /** Deletes everything the company owns, its audio, then the company itself. Cannot be undone. */
    public function erase(Tenant $tenant): void
    {
        $this->tenancy->run($tenant, function () {
            $settings = Setting::query()->pluck('key');
            $users = User::query()->get(['id', 'email']);

            foreach (Recording::query()->lazyById() as $recording) {
                $this->recordings->deleteAudio($recording);
                if ($recording->upload_path) {
                    Storage::disk('local')->delete($recording->upload_path);
                }
            }
            Sound::query()->lazyById()->each(fn (Sound $sound) => $sound->delete()); // removes each file too

            DB::transaction(function () use ($users) {
                foreach (self::MODELS as $model) {
                    $model::query()->delete();
                }
                DB::table('sessions')->whereIn('user_id', $users->pluck('id'))->delete();
                DB::table('password_reset_tokens')->whereIn('email', $users->pluck('email'))->delete();
            });

            foreach ($settings as $key) {
                Setting::forget($key); // clears its cache entry
            }
        });

        $tenant->delete();
    }

    /** @return array<string, mixed> */
    private function exportable(Model $model): array
    {
        $row = $model->toArray(); // hidden attributes (passwords, keys, file paths) are left out
        unset($row['tenant_id']);

        if ($model instanceof Setting && $model->key === 'ai') {
            $value = json_decode((string) $model->value, true) ?: [];
            array_walk($value, function (&$part) {
                if (is_array($part)) {
                    unset($part['api_key']);
                }
            });
            $row['value'] = json_encode($value);
        }

        return $row;
    }

    /** @param class-string<Model> $parent */
    private function exportPivot(ZipArchive $zip, string $table, string $key, string $parent): void
    {
        $rows = DB::table($table)->whereIn($key, $parent::query()->select('id'))->get();
        $zip->addFromString("data/{$table}.json", self::json($rows));
    }

    private function readme(Tenant $tenant): string
    {
        return "QuaverDesk export for {$tenant->name}, made ".now()->toDayDateTimeString()." UTC.\n\n"
            ."data/       Your records as JSON: people, calls, numbers, routing, settings, recordings' transcripts.\n"
            ."audio/      Call recordings and voicemail (by recording id) and your uploaded sounds (by sound id).\n\n"
            ."Passwords, two-factor secrets, phone passwords, SIP trunk passwords, AI provider keys and the licence\n"
            ."key are not included. This file contains personal data and call audio: store and share it securely.\n";
    }

    private static function json(mixed $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
