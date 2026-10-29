<?php

namespace App\Http\Controllers;

use App\Jobs\SyncVoiceServer;
use App\Models\User;
use App\Support\Invites;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/** A new person follows their emailed link and chooses a password. */
class InviteController extends Controller
{
    public function show(Request $request, User $user): View
    {
        abort_unless(Invites::valid($user, (string) $request->query('h')), 410, 'This link has already been used. Sign in, or ask your administrator for a new one.');

        return view('auth.invite', ['user' => $user]);
    }

    public function store(Request $request, User $user): RedirectResponse
    {
        abort_unless(Invites::valid($user, (string) $request->query('h')), 410, 'This link has already been used. Sign in, or ask your administrator for a new one.');

        $data = $request->validate(['password' => ['required', 'string', Password::defaults(), 'confirmed']]);

        $user->forceFill(['password' => $data['password']])->save();
        $user->rotateSipSecret(); // a sign-in link can also be a reset: the old phone password stops working
        SyncVoiceServer::dispatch()->afterCommit();

        Auth::guard('web')->logout();
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard')->with('status', 'Welcome! Your password is set.');
    }
}
