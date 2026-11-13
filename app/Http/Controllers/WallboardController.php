<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\User;
use App\Support\Monitoring;
use App\Support\Wallboard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Live wallboard (Pro): everyone can watch calls, queues and who's free, refreshed every few seconds. Listening in stays with chosen admins. */
class WallboardController extends Controller
{
    public const REFRESH_SECONDS = 5;

    /** When on, people who aren't admins see live calls without the phone numbers. */
    public const HIDE_NUMBERS = 'wallboard.hide_numbers';

    public function show(Request $request, Wallboard $wallboard, Monitoring $monitoring): View
    {
        return view('wallboard', [
            'initial' => $this->snapshotFor($request->user(), $wallboard),
            'refresh' => self::REFRESH_SECONDS,
            'canMonitor' => $monitoring->mayMonitor($request->user()),
            'hideNumbers' => self::hidesNumbers(),
        ]);
    }

    public function data(Request $request, Wallboard $wallboard): JsonResponse
    {
        return response()->json($this->snapshotFor($request->user(), $wallboard), 200, ['Cache-Control' => 'no-store, private']);
    }

    /** Admins only: whether everyone else sees callers' numbers. */
    public function settings(Request $request): RedirectResponse
    {
        $request->validate(['hide_numbers' => ['nullable', 'boolean']]);
        Setting::put(self::HIDE_NUMBERS, $request->boolean('hide_numbers'));

        return back()->with('status', $request->boolean('hide_numbers')
            ? "Callers' numbers are now hidden on the wallboard, except from admins."
            : "Everyone can now see callers' numbers on the wallboard.");
    }

    public static function hidesNumbers(): bool
    {
        return (bool) Setting::get(self::HIDE_NUMBERS, false);
    }

    /** @return array<string, mixed> */
    private function snapshotFor(User $user, Wallboard $wallboard): array
    {
        $snapshot = $wallboard->snapshot();
        if (! $user->isAdmin() && self::hidesNumbers()) {
            $snapshot['live'] = collect($snapshot['live'])->map(fn (array $c) => ['from' => null, 'to' => null] + $c)->values();
        }

        return $snapshot;
    }
}
