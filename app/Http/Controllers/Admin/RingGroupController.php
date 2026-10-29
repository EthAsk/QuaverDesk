<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Routing\RingGroupConfig;
use App\Domain\Telephony\Dialplan;
use App\Http\Controllers\Controller;
use App\Models\RingGroup;
use App\Models\User;
use App\Rules\ValidDestination;
use App\Support\DestinationCatalog;
use App\Tenancy\TenantRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class RingGroupController extends Controller
{
    public function index(DestinationCatalog $catalog): View
    {
        return view('admin.ring-groups.index', [
            'groups' => RingGroup::query()->withCount('members')->orderBy('name')->get(),
            'catalog' => $catalog,
        ]);
    }

    public function create(DestinationCatalog $catalog): View
    {
        return $this->form(new RingGroup(['strategy' => RingGroupConfig::SIMULTANEOUS, 'ring_seconds' => 20]), $catalog);
    }

    public function edit(RingGroup $group, DestinationCatalog $catalog): View
    {
        return $this->form($group->load('members'), $catalog);
    }

    public function store(Request $request): RedirectResponse
    {
        $group = DB::transaction(fn () => $this->save(new RingGroup(), $request));

        return redirect()->route('admin.ring-groups')->with('status', "Ring group \"{$group->name}\" created.");
    }

    public function update(Request $request, RingGroup $group): RedirectResponse
    {
        DB::transaction(fn () => $this->save($group, $request));

        return redirect()->route('admin.ring-groups')->with('status', "Ring group \"{$group->name}\" saved.");
    }

    public function destroy(RingGroup $group): RedirectResponse
    {
        $group->delete();

        return back()->with('status', "Ring group \"{$group->name}\" deleted. Anything that pointed at it now plays the unavailable message.");
    }

    private function form(RingGroup $group, DestinationCatalog $catalog): View
    {
        $options = $catalog->options();
        if ($group->exists) {
            unset($options['Ring groups']["ring_group:{$group->id}"]); // no falling back to itself
        }

        return view('admin.ring-groups.form', [
            'group' => $group,
            'users' => User::query()->orderBy('name')->get(),
            'positions' => $group->exists ? $group->members->pluck('pivot.position', 'id')->all() : [],
            'options' => $options,
        ]);
    }

    private function save(RingGroup $group, Request $request): RingGroup
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            // A short number phones can dial, or transfer calls to; never one a person has.
            'extension' => ['nullable', 'string', 'regex:'.Dialplan::EXTENSION,
                TenantRule::unique('ring_groups', 'extension')->ignore($group->id),
                Rule::notIn(User::query()->whereNotNull('extension')->pluck('extension')->all())],
            'strategy' => ['required', Rule::in([RingGroupConfig::SIMULTANEOUS, RingGroupConfig::IN_ORDER])],
            'ring_seconds' => ['required', 'integer', 'min:5', 'max:120'],
            'fallback' => ['nullable', new ValidDestination()],
            'members' => ['nullable', 'array'],
            'members.*' => ['integer', TenantRule::exists('users')],
            'positions' => ['nullable', 'array'],
            'positions.*' => ['nullable', 'integer', 'min:0', 'max:999'],
        ], [
            'extension.regex' => 'Extensions are 3 to 5 digits, starting with 2 to 8.',
            'extension.unique' => 'Another ring group already has that extension.',
            'extension.not_in' => 'Someone already has that extension.',
        ]);

        if ($group->exists && ($data['fallback'] ?? null) === "ring_group:{$group->id}") {
            $data['fallback'] = null;
        }

        $group->fill([
            'name' => $data['name'],
            'extension' => $data['extension'] ?? null,
            'strategy' => $data['strategy'],
            'ring_seconds' => $data['ring_seconds'],
            'fallback' => $data['fallback'] ?? null,
        ])->save();

        $sync = [];
        foreach ($data['members'] ?? [] as $userId) {
            $sync[$userId] = ['position' => (int) ($data['positions'][$userId] ?? 0)];
        }
        $group->members()->sync($sync);

        return $group;
    }
}
