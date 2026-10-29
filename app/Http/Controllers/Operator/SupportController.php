<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\OperatorAudit;
use App\Models\SupportSession;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\Tenancy;
use App\Tenancy\TenantRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * An operator asks to visit a company as one of its people. The visit starts
 * from a single-use link on the company's own address (sessions don't cross
 * addresses), valid for two minutes.
 */
class SupportController extends Controller
{
    public function store(Request $request, Tenant $tenant, Tenancy $tenancy): RedirectResponse
    {
        abort_unless($tenant->isActive(), 409, 'Resume the company before visiting it.');

        $data = $tenancy->run($tenant, fn () => $request->validate([
            'user_id' => ['required', 'integer', TenantRule::exists('users')],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ], ['reason.min' => 'Say why you need to sign in (the company is told).']));

        $token = bin2hex(random_bytes(32));
        $visit = SupportSession::query()->create([
            'operator_id' => $request->user('operator')->id,
            'tenant_id' => $tenant->id,
            'user_id' => $data['user_id'],
            'reason' => $data['reason'],
            'token_hash' => hash('sha256', $token),
            'token_expires_at' => now()->addMinutes(2),
        ]);
        $as = $tenancy->run($tenant, fn () => User::query()->find($data['user_id']));
        OperatorAudit::record('support.requested', $tenant, ['visit' => $visit->id, 'as' => $as->email, 'reason' => $data['reason']]);

        return redirect()->away($tenant->url().'/support/'.$token);
    }
}
