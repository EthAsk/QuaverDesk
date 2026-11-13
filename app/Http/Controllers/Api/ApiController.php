<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Call;
use App\Models\PhoneNumber;
use App\Models\User;
use App\Support\LicenceManager;
use App\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** Read-only API (v1) for a company's own software. Never returns secrets, audio or transcripts. */
class ApiController extends Controller
{
    public function me(LicenceManager $licences, Tenancy $tenancy): JsonResponse
    {
        $l = $licences->current();

        return response()->json(['data' => ['company' => $tenancy->current()->name, 'plan' => $l->plan->value, 'users_allowed' => $l->maxUsers(), 'users' => User::query()->seated()->count()]]);
    }

    public function users(Request $request): JsonResponse
    {
        return $this->page($request, User::query()->orderBy('id'), fn (User $u) => $this->userData($u));
    }

    public function showUser(string $id): JsonResponse
    {
        $user = User::query()->whereKey($id)->first();

        return $user ? response()->json(['data' => $this->userData($user)]) : $this->notFound();
    }

    public function numbers(Request $request): JsonResponse
    {
        return $this->page($request, PhoneNumber::query()->with('trunk')->orderBy('id'), fn (PhoneNumber $n) => [
            'id' => $n->id, 'number' => $n->e164, 'trunk' => $n->trunk?->name, 'record_calls' => (bool) $n->record_calls,
        ]);
    }

    /** Calls, newest first. Filters: from, to (dates), direction, number (either end), user_id. */
    public function calls(Request $request): JsonResponse
    {
        $request->validate([
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'], 'direction' => ['nullable', 'in:inbound,outbound,internal'],
            'number' => ['nullable', 'string', 'max:30'], 'user_id' => ['nullable', 'integer'],
        ]);
        $q = $this->callQuery()->orderByDesc('id')
            ->when($request->query('from'), fn (Builder $q, $d) => $q->where('created_at', '>=', Carbon::parse($d)->startOfDay()))
            ->when($request->query('to'), fn (Builder $q, $d) => $q->where('created_at', '<=', Carbon::parse($d)->endOfDay()))
            ->when($request->query('direction'), fn (Builder $q, $d) => $q->where('direction', $d))
            ->when($request->query('user_id'), fn (Builder $q, $id) => $q->where('user_id', $id))
            ->when($request->query('number'), fn (Builder $q, $n) => $q->where(fn (Builder $q) => $q->where('from', $n)->orWhere('to', $n)));

        return $this->page($request, $q, fn (Call $c) => $this->callData($c));
    }

    public function showCall(string $id): JsonResponse
    {
        $call = $this->callQuery()->whereKey($id)->first();

        return $call ? response()->json(['data' => $this->callData($call)]) : $this->notFound();
    }

    /** Transfers and follow-me rings are parts of a call already listed. */
    private function callQuery(): Builder
    {
        return Call::query()->where(fn (Builder $q) => $q->whereNull('leg')->orWhere('leg', Call::LEG_ON_NET));
    }

    /** @return array<string, mixed> */
    private function userData(User $user): array
    {
        return [
            'id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'extension' => $user->extension, 'role' => $user->role,
            'type' => match (true) { $user->isForwardOnly() => 'forward_only', $user->isPhoneOnly() => 'desk_phone_only', default => 'user' },
            'suspended' => $user->isSuspended(),
        ];
    }

    /** @return array<string, mixed> */
    private function callData(Call $c): array
    {
        return [
            'id' => $c->id, 'direction' => $c->direction, 'from' => $c->from, 'to' => $c->to, 'caller_name' => $c->caller_name,
            'status' => $c->status, 'duration_seconds' => $c->duration_seconds, 'user_id' => $c->user_id,
            'started_at' => $c->created_at->toIso8601String(),
        ];
    }

    private function page(Request $request, Builder $query, callable $map): JsonResponse
    {
        $per = max(1, min(100, (int) $request->query('per_page', 50)));
        $page = $query->paginate($per);

        return response()->json([
            'data' => $page->getCollection()->map($map)->values(),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $per, 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['error' => 'not_found', 'message' => 'No such record.'], 404);
    }
}
