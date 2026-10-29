<?php

namespace App\Http\Controllers;

use App\Mail\SupportVisitStarted;
use App\Models\OperatorAudit;
use App\Models\SupportSession;
use App\Models\User;
use App\Tenancy\Tenancy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;

/** The company side of an operator's support visit (see Operator\SupportController). */
class SupportVisitController extends Controller
{
    public function start(Request $request, string $token, Tenancy $tenancy): RedirectResponse
    {
        $visit = SupportSession::query()
            ->where('token_hash', hash('sha256', $token))
            ->where('tenant_id', $tenancy->current()->id)
            ->whereNull('started_at')
            ->where('token_expires_at', '>', now())
            ->first();
        abort_unless($visit, 404);

        $user = User::query()->find($visit->user_id);
        abort_unless($user, 404);

        $visit->forceFill(['started_at' => now(), 'ends_at' => now()->addMinutes((int) config('tenancy.support_minutes'))])->save();

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put('support_visit', $visit->id);
        // The operator signed in with a password and a code: that stands in for the person's password.
        $request->session()->put('auth.password_confirmed_at', time());

        OperatorAudit::query()->create([
            'operator_id' => $visit->operator_id, 'action' => 'support.started', 'tenant_id' => $visit->tenant_id,
            'tenant_name' => $tenancy->current()->name, 'details' => ['visit' => $visit->id, 'as' => $user->email], 'ip_address' => $request->ip(),
        ]);
        foreach (User::query()->where('role', User::ROLE_OWNER)->get() as $owner) {
            Mail::to($owner)->queue(new SupportVisitStarted($owner, $visit, $user));
        }

        return redirect()->route('dashboard');
    }

    public function end(Request $request): RedirectResponse
    {
        if ($id = $request->session()->get('support_visit')) {
            SupportSession::query()->whereKey($id)->update(['ends_at' => now()]);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', 'Support visit ended.');
    }
}
