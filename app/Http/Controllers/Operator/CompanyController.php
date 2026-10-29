<?php

namespace App\Http\Controllers\Operator;

use App\Domain\Licensing\Plan;
use App\Http\Controllers\Controller;
use App\Jobs\SyncVoiceServer;
use App\Mail\UserInvited;
use App\Models\OperatorAudit;
use App\Models\SipTrunk;
use App\Models\SupportSession;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Invites;
use App\Tenancy\Quotas;
use App\Tenancy\TenantData;
use App\Tenancy\Tenancy;
use Carbon\CarbonInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** The operator's view of every company: usage, adding, suspending, exporting and deleting. */
class CompanyController extends Controller
{
    public function __construct(private readonly Tenancy $tenancy)
    {
    }

    public function index(): View
    {
        return view('operator.companies.index', [
            'companies' => Tenant::query()->orderBy('name')->get(),
            'usage' => $this->usage(),
        ]);
    }

    public function create(): View
    {
        return view('operator.companies.create', ['base' => config('tenancy.base_domain')]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge(['slug' => strtolower((string) $request->input('slug')), 'domain' => $request->filled('domain') ? strtolower((string) $request->input('domain')) : null]);
        $data = $request->validate(Tenant::rules() + [
            'owner_name' => ['required', 'string', 'max:255'],
            // Unique across every company: sign-in finds people by email alone.
            'owner_email' => ['required', 'email', 'max:255', 'unique:users,email'],
        ], Tenant::RULE_MESSAGES);

        $tenant = Tenant::query()->create($data);
        $operator = $request->user('operator');

        $link = $this->tenancy->run($tenant, function () use ($tenant, $data, $operator) {
            $owner = new User(['name' => $data['owner_name'], 'email' => strtolower($data['owner_email']), 'password' => Str::password(32)]);
            $owner->role = User::ROLE_OWNER;
            $owner->save();

            $link = $this->tenancy->withUrlsOf($tenant, fn () => Invites::link($owner));
            Mail::to($owner)->queue(new UserInvited($owner, $link, config('app.name').' ('.$operator->name.')'));

            return $link;
        });
        OperatorAudit::record('company.created', $tenant, ['owner' => strtolower($data['owner_email'])]);

        return redirect()->route('operator.companies.show', $tenant)
            ->with('invited', ['email' => strtolower($data['owner_email']), 'link' => $link]);
    }

    public function show(Tenant $tenant): View
    {
        return view('operator.companies.show', [
            'company' => $tenant,
            'usage' => $this->usage()[$tenant->id] ?? null,
            'lastMonth' => $this->usage(now()->subMonthNoOverflow()->startOfMonth(), now()->startOfMonth())[$tenant->id] ?? null,
            'quota' => $this->tenancy->run($tenant, fn () => ['storage' => app(Quotas::class)->storageBytes(), 'outside' => app(Quotas::class)->outsideSecondsThisMonth()]),
            'trunks' => $this->tenancy->run($tenant, fn () => SipTrunk::query()->orderBy('name')->get()),
            'people' => $this->tenancy->run($tenant, fn () => User::query()->orderByRaw("role = 'owner' desc")->orderBy('name')->get()),
            'visits' => SupportSession::query()->with('operator')->where('tenant_id', $tenant->id)->latest()->limit(10)->get(),
            'audit' => OperatorAudit::query()->with('operator')->where('tenant_id', $tenant->id)->latest('id')->limit(20)->get(),
        ]);
    }

    public function plan(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate(Tenant::planRules());
        $before = $tenant->only(Tenant::PLAN_FIELDS);
        $tenant->forceFill($data)->save();

        $changes = collect($data)->filter(fn ($value, $field) => (string) ($before[$field] instanceof Plan ? $before[$field]->value : $before[$field]) !== (string) $value)->all();
        if ($changes) {
            OperatorAudit::record('company.plan', $tenant, $changes);
        }

        return back()->with('status', "{$tenant->name}'s plan is saved.");
    }

    public function status(Request $request, Tenant $tenant): RedirectResponse
    {
        $suspend = $request->validate(['status' => ['required', 'in:active,suspended']])['status'] === Tenant::STATUS_SUSPENDED;
        $tenant->forceFill(['status' => $suspend ? Tenant::STATUS_SUSPENDED : Tenant::STATUS_ACTIVE])->save();
        OperatorAudit::record($suspend ? 'company.suspended' : 'company.resumed', $tenant);

        return back()->with('status', $suspend
            ? "{$tenant->name} is suspended: nobody can sign in and its calls are refused, except 999 and 112. Its data is kept."
            : "{$tenant->name} is active again.");
    }

    public function export(Tenant $tenant, TenantData $data): BinaryFileResponse
    {
        $path = $data->export($tenant);
        OperatorAudit::record('company.exported', $tenant);

        return response()->download($path)->deleteFileAfterSend();
    }

    public function destroy(Request $request, Tenant $tenant, TenantData $data): RedirectResponse
    {
        $request->validate(['confirm' => ['required', 'in:'.$tenant->slug]], ['confirm.in' => 'Type the company\'s address name exactly to confirm.']);
        if ($tenant->isActive()) {
            return back()->withErrors(['confirm' => 'Suspend the company before deleting it.']);
        }

        // A last copy stays on the server, in case the deletion was a mistake. Delete it when no longer needed.
        $export = $data->export($tenant);
        $name = $tenant->name;
        OperatorAudit::record('company.deleted', $tenant, ['export' => basename($export)]);
        $data->erase($tenant);
        SyncVoiceServer::dispatch();

        return redirect()->route('operator.companies')
            ->with('status', "{$name} and all its data have been deleted. A final export was kept at {$export}.");
    }

    /**
     * Usage by company id for a period. Minutes are split by direction and by
     * whose trunk carried the call ("ours": trunks we supply and bill for).
     * Inbound counts the whole call (the trunk is connected from the moment we
     * answer); outbound counts connected calls, including their ringing time,
     * so it runs slightly high until the carrier's records are used.
     *
     * @return array<int, array{users: int, numbers: int, recordings: int, minutes: array{in: int, in_ours: int, out: int, out_ours: int}}>
     */
    private function usage(?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        $from ??= now()->startOfMonth();
        $to ??= now()->addSecond();

        $count = fn (string $table) => DB::table($table)->groupBy('tenant_id')->pluck(DB::raw('count(*)'), 'tenant_id');
        $users = $count('users');
        $numbers = $count('phone_numbers');
        $recordings = DB::table('recordings')->where('status', 'stored')->groupBy('tenant_id')->pluck(DB::raw('count(*)'), 'tenant_id');

        $seconds = DB::table('calls')
            ->leftJoin('sip_trunks', 'sip_trunks.id', '=', 'calls.sip_trunk_id')
            ->where('calls.created_at', '>=', $from)->where('calls.created_at', '<', $to)
            ->where(fn ($q) => $q->where('calls.direction', 'inbound')
                ->orWhere(fn ($q) => $q->where('calls.direction', 'outbound')->where('calls.status', 'completed')))
            ->groupByRaw('calls.tenant_id, calls.direction, coalesce(sip_trunks.supplied_by_operator, 0)')
            ->selectRaw('calls.tenant_id, calls.direction, coalesce(sip_trunks.supplied_by_operator, 0) as ours, coalesce(sum(calls.duration_seconds), 0) as seconds')
            ->get();

        $usage = [];
        foreach (Tenant::query()->pluck('id') as $id) {
            $usage[$id] = [
                'users' => (int) ($users[$id] ?? 0),
                'numbers' => (int) ($numbers[$id] ?? 0),
                'recordings' => (int) ($recordings[$id] ?? 0),
                'minutes' => ['in' => 0, 'in_ours' => 0, 'out' => 0, 'out_ours' => 0],
            ];
        }
        foreach ($seconds as $row) {
            if (! isset($usage[$row->tenant_id])) {
                continue;
            }
            $dir = $row->direction === 'inbound' ? 'in' : 'out';
            $minutes = (int) ceil($row->seconds / 60);
            $usage[$row->tenant_id]['minutes'][$dir] += $minutes;
            if ((int) $row->ours === 1) {
                $usage[$row->tenant_id]['minutes'][$dir.'_ours'] += $minutes;
            }
        }

        return $usage;
    }

    /** Mark one of the company's trunks as supplied by us (its calls are ours to bill), or not. */
    public function trunk(Request $request, Tenant $tenant, int $trunk): RedirectResponse
    {
        $supplied = (bool) $request->validate(['supplied' => ['required', 'boolean']])['supplied'];
        $record = $this->tenancy->run($tenant, fn () => SipTrunk::query()->findOrFail($trunk));
        $record->forceFill(['supplied_by_operator' => $supplied])->save();
        OperatorAudit::record($supplied ? 'trunk.supplied' : 'trunk.not-supplied', $tenant, ['trunk' => $record->name]);

        return back()->with('status', $supplied ? "Calls on {$record->name} now count as ours." : "Calls on {$record->name} now count as theirs.");
    }
}
