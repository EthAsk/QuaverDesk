<?php

namespace App\Tenancy;

use App\Domain\Routing\Destination;
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
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * Restores one company from its export (TenantData::export) as a new
 * company, without touching any other. Every record gets a new id, and every
 * reference between them (people in ring groups, menu options, a number's
 * destination, hold music…) is pointed at the new ids.
 *
 * Exports never hold secrets, so after a restore everyone sets a new password
 * and two-factor, trunk passwords are entered again, and desk phones are given
 * new settings. A restore refuses to start if any email, phone number or call
 * id already belongs to a company on the service (usually the original, which
 * should be deleted first).
 */
class TenantRestore
{
    /** The tables an export holds, parents first, and the model that owns each. */
    private const MODELS = [
        'sounds' => Sound::class, 'sip_trunks' => SipTrunk::class, 'mailboxes' => Mailbox::class, 'users' => User::class,
        'ring_groups' => RingGroup::class, 'ivr_menus' => IvrMenu::class, 'phone_numbers' => PhoneNumber::class,
        'desk_phones' => DeskPhone::class, 'calls' => Call::class, 'recordings' => Recording::class,
        'acknowledgements' => Acknowledgement::class, 'settings' => Setting::class,
    ];

    /** Columns that point at another record: column => table. */
    private const REFERENCES = [
        'users' => ['mailbox_id' => 'mailboxes'],
        'ivr_menus' => ['greeting_sound_id' => 'sounds'],
        'mailboxes' => ['greeting_sound_id' => 'sounds'],
        'phone_numbers' => ['sip_trunk_id' => 'sip_trunks'],
        'desk_phones' => ['user_id' => 'users'],
        'calls' => ['user_id' => 'users', 'sip_trunk_id' => 'sip_trunks'],
        'recordings' => ['call_id' => 'calls', 'mailbox_id' => 'mailboxes'],
        'acknowledgements' => ['user_id' => 'users'],
    ];

    /** Destination types and the table their id is in. */
    private const DESTINATIONS = [Destination::USER => 'users', Destination::RING_GROUP => 'ring_groups',
        Destination::IVR => 'ivr_menus', Destination::VOICEMAIL => 'mailboxes'];

    /** Never restored: whatever the file says, these are recreated or left empty. */
    private const NEVER = ['id', 'tenant_id', 'password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes',
        'two_factor_confirmed_at', 'sip_secret', 'auth_password', 'sip_password', 'gateway_token', 'supplied_by_operator',
        'path', 'upload_path', 'bytes', 'file'];

    private const MAX_JSON_BYTES = 200 * 1024 * 1024;

    /** @var array<string, array<int, int>> old id => new id, by table */
    private array $ids = [];

    public function __construct(
        private readonly Tenancy $tenancy,
        private readonly RecordingStore $recordings,
    ) {
    }

    /**
     * @return array{tenant: Tenant, counts: array<string, int>}
     *
     * @throws RuntimeException with a reason the operator can act on; nothing is changed
     */
    public function restore(string $path, string $slug, ?string $name = null): array
    {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('That file is not a QuaverDesk export (it is not a zip).');
        }

        try {
            $company = $this->json($zip, 'company.json');
            $data = [];
            foreach (array_keys(self::MODELS) as $table) {
                $data[$table] = $this->json($zip, "data/{$table}.json");
            }
            $pivots = ['ring_group_user' => $this->json($zip, 'data/ring_group_user.json'), 'mailbox_user' => $this->json($zip, 'data/mailbox_user.json')];
            $this->checkNoClashes($data);

            $tenant = DB::transaction(function () use ($slug, $name, $company, $data, $pivots) {
                $tenant = Tenant::query()->create(['name' => $name ?: (string) ($company['name'] ?? $slug), 'slug' => $slug]);
                $tenant->forceFill(['status' => Tenant::STATUS_SUSPENDED])->save(); // the operator checks it, then resumes it

                $this->tenancy->run($tenant, function () use ($tenant, $data, $pivots) {
                    foreach (self::MODELS as $table => $model) {
                        foreach ($data[$table] as $row) {
                            $this->insert($tenant, $table, $model, (array) $row);
                        }
                    }
                    $this->pointDestinationsAtNewIds();
                    foreach ($pivots as $table => $rows) {
                        $this->insertPivot($table, $rows);
                    }
                });

                return $tenant;
            });

            // Audio last: if this fails part way, the records are there and the operator can retry the audio.
            $this->tenancy->run($tenant, fn () => $this->restoreAudio($zip));

            return ['tenant' => $tenant, 'counts' => array_map('count', $data)];
        } finally {
            $zip->close();
        }
    }

    /** @param array<string, list<array<string, mixed>>> $data */
    private function checkNoClashes(array $data): void
    {
        $clashes = $this->tenancy->everywhere(fn () => array_filter([
            'email addresses' => User::query()->whereIn('email', array_map('strtolower', array_column($data['users'], 'email')))->count(),
            'phone numbers' => PhoneNumber::query()->whereIn('e164', array_column($data['phone_numbers'], 'e164'))->count(),
            'calls' => Call::query()->whereIn('carrier_sid', array_column($data['calls'], 'carrier_sid'))->count(),
            'recordings' => Recording::query()->whereIn('carrier_sid', array_column($data['recordings'], 'carrier_sid'))->count(),
        ]));

        if ($clashes) {
            $list = implode(', ', array_map(fn ($what, $n) => "{$n} {$what}", array_keys($clashes), $clashes));

            throw new RuntimeException("Some of this company's data is already on the service ({$list}). Delete the original company first, or restore it on another install.");
        }
    }

    /** @param class-string<Model> $model */
    private function insert(Tenant $tenant, string $table, string $model, array $row): void
    {
        $old = (int) ($row['id'] ?? 0);
        $columns = array_flip(Schema::getColumnListing($table));
        $attributes = array_diff_key(array_intersect_key($row, $columns), array_flip(self::NEVER));

        foreach (self::REFERENCES[$table] ?? [] as $column => $parent) {
            if (isset($attributes[$column])) {
                $attributes[$column] = $this->ids[$parent][(int) $attributes[$column]] ?? null;
            }
        }

        $record = new $model();
        $record->forceFill($attributes + ['tenant_id' => $tenant->id]);
        match ($table) {
            'users' => $record->forceFill(['email' => strtolower((string) $record->email), 'password' => Str::password(32)]),
            'sip_trunks' => $record->forceFill(['gateway_token' => bin2hex(random_bytes(6)), 'register' => false,
                'last_error' => $record->auth_username ? 'Restored from a backup without its password. Remove this trunk and add it again.' : null]),
            'desk_phones' => $record->forceFill(['sip_username' => 'u'.$record->user_id.'-'.bin2hex(random_bytes(3)),
                'sip_password' => Str::password(20, symbols: false)]),
            'sounds' => $record->forceFill(['file' => bin2hex(random_bytes(20))]),
            'recordings' => $record->forceFill(['status' => ($row['status'] ?? null) === 'stored' ? 'deleted' : ($row['status'] ?? 'deleted')]),
            'settings' => $record->key === 'sounds.hold_music'
                ? $record->forceFill(['value' => json_encode($this->ids['sounds'][(int) json_decode((string) $record->value)] ?? null)])
                : null,
            default => null,
        };

        // A desk phone or call whose person wasn't in the export is left out.
        if (in_array($table, ['desk_phones', 'acknowledgements'], true) && ! $record->user_id) {
            return;
        }

        $record->saveQuietly(); // no voice server syncs or prompt generation mid-restore
        $this->ids[$table][$old] = $record->getKey();
    }

    private function pointDestinationsAtNewIds(): void
    {
        $map = function (?string $value): ?string {
            $destination = Destination::parse($value);
            if ($destination === null || $destination->type === Destination::HANGUP) {
                return $value === null ? null : (string) ($destination ?? Destination::hangup());
            }
            $id = $this->ids[self::DESTINATIONS[$destination->type]][$destination->id] ?? null;

            return $id ? (string) new Destination($destination->type, $id, $destination->step) : Destination::HANGUP;
        };

        foreach (PhoneNumber::query()->get() as $number) {
            $number->forceFill(['destination' => $map($number->destination), 'closed_destination' => $map($number->closed_destination)])->saveQuietly();
        }
        foreach (RingGroup::query()->get() as $group) {
            $group->forceFill(['fallback' => $map($group->fallback)])->saveQuietly();
        }
        foreach (IvrMenu::query()->get() as $menu) {
            $options = array_map(fn ($value) => is_string($value) ? $map($value) : Destination::HANGUP, (array) $menu->options);
            $menu->forceFill(['fallback' => $map($menu->fallback), 'options' => $options])->saveQuietly();
        }
    }

    private function insertPivot(string $table, array $rows): void
    {
        [$parentKey, $parentTable] = $table === 'ring_group_user' ? ['ring_group_id', 'ring_groups'] : ['mailbox_id', 'mailboxes'];
        foreach ($rows as $row) {
            $row = (array) $row;
            $parent = $this->ids[$parentTable][(int) ($row[$parentKey] ?? 0)] ?? null;
            $user = $this->ids['users'][(int) ($row['user_id'] ?? 0)] ?? null;
            if ($parent && $user) {
                DB::table($table)->insert([$parentKey => $parent, 'user_id' => $user] + (isset($row['position']) ? ['position' => (int) $row['position']] : []));
            }
        }
    }

    private function restoreAudio(ZipArchive $zip): void
    {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (preg_match('#^audio/recordings/(\d+)\.(wav|m4a)$#', $name, $m) && ($id = $this->ids['recordings'][(int) $m[1]] ?? null)) {
                $this->recordings->put(Recording::query()->findOrFail($id), (string) $zip->getFromIndex($i));
            } elseif (preg_match('#^audio/sounds/(\d+)\.wav$#', $name, $m) && ($id = $this->ids['sounds'][(int) $m[1]] ?? null)) {
                $audio = (string) $zip->getFromIndex($i);
                if (! str_starts_with($audio, 'RIFF')) {
                    continue;
                }
                $sound = Sound::query()->findOrFail($id);
                @mkdir(dirname($sound->path()), 0750, true);
                file_put_contents($sound->path(), $audio);
                $sound->forceFill(['bytes' => strlen($audio)])->saveQuietly();
            }
        }
    }

    /** @return array<mixed> */
    private function json(ZipArchive $zip, string $name): array
    {
        $stat = $zip->statName($name);
        if ($stat === false) {
            throw new RuntimeException("That file is not a QuaverDesk export ({$name} is missing).");
        }
        if ($stat['size'] > self::MAX_JSON_BYTES) {
            throw new RuntimeException("{$name} is too large to restore.");
        }

        $data = json_decode((string) $zip->getFromName($name), true);
        if (! is_array($data)) {
            throw new RuntimeException("{$name} in the export can't be read.");
        }

        return $data;
    }
}
