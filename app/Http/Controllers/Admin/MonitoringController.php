<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Call;
use App\Models\MonitorEvent;
use App\Models\User;
use App\Support\Monitoring;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MonitoringController extends Controller
{
    public function show(Monitoring $monitoring): View
    {
        return view('admin.monitoring', [
            'admins' => User::query()->whereIn('role', [User::ROLE_OWNER, User::ROLE_ADMIN])->orderBy('name')->get(['id', 'name']),
            'supervisors' => $monitoring->supervisorIds(),
            'ack' => $monitoring->acknowledgement(),
            'events' => MonitorEvent::query()->with(['user:id,name', 'call:id,from,to,direction'])->latest('id')->limit(30)->get(),
        ]);
    }

    /** The owner says callers and staff are told; until then nobody can listen in. */
    public function acknowledge(Request $request, Monitoring $monitoring): RedirectResponse
    {
        abort_unless($request->user()->isOwner(), 403);
        $request->validate(['confirm' => ['accepted']], ['confirm.accepted' => 'Tick the box to confirm.']);
        $monitoring->acknowledge($request->user());

        return back()->with('status', 'Recorded. Chosen admins can now listen in from the live wallboard.');
    }

    public function supervisors(Request $request, Monitoring $monitoring): RedirectResponse
    {
        abort_unless($request->user()->isOwner(), 403);
        $monitoring->setSupervisors((array) $request->input('supervisors', []));

        return back()->with('status', 'Saved.');
    }

    /** From the wallboard: ring the supervisor's phone and join them to the live call. */
    public function start(Request $request, Call $call, Monitoring $monitoring): JsonResponse
    {
        abort_unless($monitoring->mayMonitor($request->user()), 403);
        $mode = (string) $request->validate(['mode' => ['required', 'in:'.implode(',', Monitoring::MODES)]])['mode'];

        $result = $monitoring->start($request->user(), $call, $mode);

        return response()->json(['result' => $result, 'message' => match ($result) {
            Monitoring::STARTED => 'Your phone is ringing. Answer it to '.['listen' => 'listen in', 'whisper' => 'whisper to the agent', 'barge' => 'join the call'][$mode].'.',
            'emergency' => 'Emergency calls can never be monitored.',
            'not_live' => 'That call has ended.',
            'own_call' => "You can't monitor your own call.",
            'no_phone' => 'You have no phone signed in to ring.',
            default => 'The phone system could not do that.',
        }], $result === Monitoring::STARTED ? 200 : 409);
    }
}
