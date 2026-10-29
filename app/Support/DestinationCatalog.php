<?php

namespace App\Support;

use App\Domain\Routing\Destination;
use App\Models\IvrMenu;
use App\Models\Mailbox;
use App\Models\RingGroup;
use App\Models\User;

/** The destinations an admin can pick from in routing forms. */
class DestinationCatalog
{
    /** @return array<string, array<string, string>> grouped options: group label => [value => label] */
    public function options(): array
    {
        return [
            'People' => User::query()->orderBy('name')->get()
                ->mapWithKeys(fn (User $u) => ["user:{$u->id}" => "{$u->name} (ext {$u->dialable()})"])->all(),
            'Ring groups' => RingGroup::query()->orderBy('name')->get()
                ->mapWithKeys(fn (RingGroup $g) => ["ring_group:{$g->id}" => $g->name])->all(),
            'IVR menus' => IvrMenu::query()->orderBy('name')->get()
                ->mapWithKeys(fn (IvrMenu $m) => ["ivr:{$m->id}" => $m->name])->all(),
            'Voicemail' => Mailbox::query()->orderBy('name')->get()
                ->mapWithKeys(fn (Mailbox $m) => ["voicemail:{$m->id}" => "Voicemail: {$m->name}"])->all(),
            'Other' => ['hangup' => 'Play "unavailable" message and hang up'],
        ];
    }

    public function exists(?string $value): bool
    {
        $d = Destination::parse($value);
        if ($d === null || $d->step !== 0) {
            return false;
        }

        return match ($d->type) {
            Destination::USER => User::query()->whereKey($d->id)->exists(),
            Destination::RING_GROUP => RingGroup::query()->whereKey($d->id)->exists(),
            Destination::IVR => IvrMenu::query()->whereKey($d->id)->exists(),
            Destination::VOICEMAIL => Mailbox::query()->whereKey($d->id)->exists(),
            Destination::HANGUP => true,
        };
    }

    public function label(?string $value): string
    {
        foreach ($this->options() as $group) {
            if (isset($group[$value])) {
                return $group[$value];
            }
        }

        return '—';
    }
}
