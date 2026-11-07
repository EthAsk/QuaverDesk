<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Telephony\Dialplan;
use App\Http\Controllers\Controller;
use App\Models\CallQueue;
use App\Models\RingGroup;
use App\Models\Sound;
use App\Models\User;
use App\Rules\ValidDestination;
use App\Support\DestinationCatalog;
use App\Tenancy\TenantRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CallQueueController extends Controller
{
    public function index(DestinationCatalog $catalog): View
    {
        return view('admin.queues.index', [
            'queues' => CallQueue::query()->withCount(['members', 'agents', 'callers as waiting_count' => fn ($q) => $q->waiting()])->orderBy('name')->get(),
            'catalog' => $catalog,
        ]);
    }

    public function create(DestinationCatalog $catalog): View
    {
        return $this->form(new CallQueue(['ring_seconds' => 20, 'max_wait_seconds' => 300, 'announce_position' => true, 'overflow_when_empty' => true]), $catalog);
    }

    public function edit(CallQueue $queue, DestinationCatalog $catalog): View
    {
        return $this->form($queue->load('members'), $catalog);
    }

    public function store(Request $request): RedirectResponse
    {
        $queue = DB::transaction(fn () => $this->save(new CallQueue(), $request));

        return redirect()->route('admin.queues')->with('status', "Call queue \"{$queue->name}\" created.");
    }

    public function update(Request $request, CallQueue $queue): RedirectResponse
    {
        DB::transaction(fn () => $this->save($queue, $request));

        return redirect()->route('admin.queues')->with('status', "Call queue \"{$queue->name}\" saved.");
    }

    public function destroy(CallQueue $queue): RedirectResponse
    {
        $queue->delete();

        return back()->with('status', "Call queue \"{$queue->name}\" deleted. Anything that pointed at it now plays the unavailable message.");
    }

    private function form(CallQueue $queue, DestinationCatalog $catalog): View
    {
        $options = $catalog->options();
        if ($queue->exists) {
            unset($options['Call queues']["queue:{$queue->id}"]); // no overflowing into itself
        }

        return view('admin.queues.form', [
            'queue' => $queue,
            'users' => User::query()->seated()->orderBy('name')->get(),
            'members' => $queue->exists ? $queue->members->mapWithKeys(fn (User $u) => [$u->id => (bool) $u->pivot->logged_in])->all() : [],
            'sounds' => Sound::query()->orderBy('name')->get(['id', 'name']),
            'options' => $options,
        ]);
    }

    private function save(CallQueue $queue, Request $request): CallQueue
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            // A short number phones can dial, or transfer calls to; never one a person or ring group has.
            'extension' => ['nullable', 'string', 'regex:'.Dialplan::EXTENSION,
                TenantRule::unique('call_queues', 'extension')->ignore($queue->id),
                Rule::notIn([...User::query()->whereNotNull('extension')->pluck('extension')->all(),
                    ...RingGroup::query()->whereNotNull('extension')->pluck('extension')->all()])],
            'greeting' => ['nullable', 'string', 'max:500'],
            'ring_seconds' => ['required', 'integer', 'min:5', 'max:120'],
            'max_wait_minutes' => ['required', 'integer', 'min:1', 'max:60'],
            'hold_sound_id' => ['nullable', 'integer', TenantRule::exists('sounds')],
            'overflow' => ['nullable', new ValidDestination()],
            'members' => ['nullable', 'array'],
            'members.*' => ['integer', TenantRule::exists('users')->where('forward_only', 0)],
        ], [
            'extension.regex' => 'Extensions are 3 to 5 digits, starting with 2 to 8.',
            'extension.unique' => 'Another call queue already has that extension.',
            'extension.not_in' => 'Someone, or a ring group, already has that extension.',
        ]);

        if ($queue->exists && ($data['overflow'] ?? null) === "queue:{$queue->id}") {
            $data['overflow'] = null;
        }

        $queue->fill([
            'name' => $data['name'],
            'extension' => $data['extension'] ?? null,
            'greeting' => $data['greeting'] ?? null,
            'ring_seconds' => $data['ring_seconds'],
            'max_wait_seconds' => $data['max_wait_minutes'] * 60,
            'announce_position' => $request->boolean('announce_position'),
            'hold_sound_id' => $data['hold_sound_id'] ?? null,
            'overflow' => $data['overflow'] ?? null,
            'overflow_when_empty' => $request->boolean('overflow_when_empty'),
        ])->save();

        // New agents start logged in; agents already here keep whether they're in or out.
        $current = $queue->members()->get()->mapWithKeys(fn (User $u) => [$u->id => (bool) $u->pivot->logged_in]);
        $sync = [];
        foreach ($data['members'] ?? [] as $userId) {
            $sync[$userId] = ['logged_in' => $current[$userId] ?? true];
        }
        $queue->members()->sync($sync);

        return $queue;
    }

    /** An admin logs an agent in or out (someone who left their desk logged in, say). */
    public function agent(Request $request, CallQueue $queue, User $user): RedirectResponse
    {
        abort_unless($queue->members()->whereKey($user->id)->exists(), 404);
        $in = $request->boolean('logged_in');
        $queue->members()->updateExistingPivot($user->id, ['logged_in' => $in]);

        return back()->with('status', "{$user->name} is now logged ".($in ? 'in to' : 'out of')." {$queue->name}.");
    }
}
