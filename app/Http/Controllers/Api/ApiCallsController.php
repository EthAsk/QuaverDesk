<?php

namespace App\Http\Controllers\Api;

use App\Domain\Licensing\Feature;
use App\Http\Controllers\Controller;
use App\Models\ApiCall;
use App\Models\ApiToken;
use App\Models\Call;
use App\Models\User;
use App\Support\ApiCallRules;
use App\Support\LicenceManager;
use App\Voice\VoiceServer;
use App\Voice\VoiceUrl;
use App\Support\DatabaseRoutingDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Place a call: ring a person's phones, and when they answer dial the number for them, under the same rules
 * as if they had dialled it. Needs a calling token (Pro). Nothing is dialled outbound until the person
 * answers, and each request is kept in api_calls as the audit trail.
 */
class ApiCallsController extends Controller
{
    public function store(Request $request, ApiCallRules $rules, VoiceServer $voice, LicenceManager $licences, DatabaseRoutingDirectory $directory): JsonResponse
    {
        /** @var ApiToken $token */
        $token = $request->attributes->get('api_token');
        if (! $token->canCall() || ! $licences->allows(Feature::ApiCalls)) {
            return $this->error(403, 'This token can only read. Make a calling token under Admin → API (Pro plan).');
        }
        if (! $request->secure() && ! app()->environment('local', 'testing')) {
            return $this->error(400, 'Use https.');
        }

        $data = $request->validate(['user_id' => ['required', 'integer'], 'to' => ['required', 'string', 'max:20']]);
        $to = preg_replace('/[\s()-]/', '', $data['to']);
        $key = $request->header('Idempotency-Key');
        if ($key !== null && ! preg_match('/^[A-Za-z0-9._-]{1,80}$/', $key)) {
            return $this->error(422, 'Idempotency-Key may only hold letters, numbers and . _ -');
        }

        // The same key within ten minutes is the same request.
        if ($key !== null && ($earlier = ApiCall::query()->where('api_token_id', $token->id)->where('idempotency_key', $key)->where('created_at', '>=', now()->subMinutes(10))->first())) {
            return response()->json(['data' => $this->resource($earlier), 'idempotent' => true]);
        }

        $user = User::query()->whereKey($data['user_id'])->first();
        $prefix = $rules->prefixOf($token, $to);
        $audit = ['api_token_id' => $token->id, 'token_name' => $token->name, 'user_id' => $user?->id, 'to' => substr($to, 0, 20), 'prefix' => $prefix,
            'idempotency_key' => $key, 'ip' => $request->ip()];

        if ($refusal = $rules->refusal($token, $user, $to)) {
            ApiCall::query()->create($audit + ['status' => 'refused', 'reason' => $refusal[1]]);

            return $this->error($refusal[0], $refusal[1]);
        }

        $targets = collect($directory->targetsFor($user))->map(fn ($t) => $t->kind === 'client' ? $t->value : (preg_match('/^sips?:([^@]+)@/', $t->value, $m) ? $m[1] : null))->filter()->values()->all();
        if ($targets === []) {
            ApiCall::query()->create($audit + ['status' => 'refused', 'reason' => 'That person has no phone to ring.']);

            return $this->error(422, 'That person has no phone to ring.');
        }

        $newCountry = $rules->newCountry($token, $prefix) && $prefix !== '+44';
        $callId = (string) Str::uuid();
        $apiCall = ApiCall::query()->create($audit + ['call_id' => $callId]);
        // The call is logged now, so the voice server's webhooks can find which company it belongs to.
        Call::query()->create(['carrier_sid' => $callId, 'api_call_id' => $apiCall->id, 'user_id' => $user->id, 'direction' => 'outbound', 'from' => $user->carrierIdentity(),
            'to' => substr($to, 0, 64), 'decision' => 'api', 'status' => 'initiated']);

        try {
            $voice->callUser($targets, $callId, $user->name, VoiceUrl::to('webhooks.voice.api-call', ['request' => $apiCall->id]));
        } catch (RuntimeException) {
            $apiCall->forceFill(['status' => 'refused', 'reason' => 'The phone system could not place the call.'])->save();
            Call::query()->where('carrier_sid', $callId)->update(['status' => 'failed', 'duration_seconds' => 0]);

            return $this->error(502, 'The phone system could not place the call.');
        }

        if ($newCountry) {
            $rules->alert("the first call to {$prefix} was requested by a calling token ({$token->name})");
        }

        return response()->json(['data' => $this->resource($apiCall)], 202);
    }

    public function show(string $id): JsonResponse
    {
        $apiCall = ApiCall::query()->whereKey($id)->where('api_token_id', request()->attributes->get('api_token')->id)->first();

        return $apiCall ? response()->json(['data' => $this->resource($apiCall)]) : $this->error(404, 'No such call request.');
    }

    /** @return array<string, mixed> */
    private function resource(ApiCall $a): array
    {
        $call = $a->call_id ? Call::query()->where('carrier_sid', $a->call_id)->first() : null;

        return [
            'id' => $a->id, 'user_id' => $a->user_id, 'to' => $a->to, 'status' => $a->outcome(), 'reason' => $a->reason,
            'requested_at' => $a->created_at->toIso8601String(), 'duration_seconds' => $call?->duration_seconds, 'call_id' => $call?->id,
        ];
    }

    private function error(int $status, string $message): JsonResponse
    {
        return response()->json(['error' => match ($status) { 403 => 'forbidden', 404 => 'not_found', 429 => 'rate_limited', 503 => 'unavailable', default => 'refused' }, 'message' => $message], $status);
    }
}
