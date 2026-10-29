<?php

namespace App\Tenancy;

use App\Domain\Telephony\DialDecision;
use App\Domain\Telephony\Dialplan;
use App\Models\Call;
use App\Models\DeskPhone;
use App\Models\PhoneNumber;
use App\Models\SipTrunk;
use App\Models\Tenant;
use App\Models\User;
use App\Voice\CallRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Which company a voice server webhook is for, worked out only from things the
 * voice server vouches for, never from what a caller can choose:
 *
 * - a phone's call: the phone that signed in (its SIP username is unique across the service);
 * - a trunk call: the number dialled, and only if the call came from that number's own trunk
 *   (by address, and by the trunk's own registration when another company shares the provider);
 * - anything later in a call: the call's id, which the voice server makes up;
 * - a transferred call: the id of the call's first leg, which our dialplan records on it.
 *
 * A suspended company is refused, except for emergency calls.
 */
class CallTenant
{
    public function __construct(private readonly Tenancy $tenancy)
    {
    }

    public function resolve(Request $request): ?Tenant
    {
        return $this->tenancy->everywhere(function () use ($request) {
            $call = new CallRequest($request);
            $first = ! $call->exiting();

            $tenantId = match (true) {
                $first && $request->is('webhooks/voice/outgoing') => $this->fromPhone($call->from()),
                $first && $request->is('webhooks/voice/inbound') => $this->fromTrunkCall($call, $request),
                $first && $request->is('webhooks/voice/transfer') => $this->fromCallId($call->originalCallId()),
                default => $this->fromCallId($call->callId()),
            };

            $tenant = $tenantId ? Tenant::query()->find($tenantId) : null;
            if ($tenant === null || $tenant->isActive()) {
                return $tenant;
            }

            // A suspended company can still call 999 and 112, and nothing else.
            return $this->isEmergencyCall($request, $call, $first) ? $tenant : null;
        });
    }

    private function isEmergencyCall(Request $request, CallRequest $call, bool $first): bool
    {
        if ($first && $request->is('webhooks/voice/outgoing')) {
            return Dialplan::isEmergency($call->to());
        }

        $id = $request->is('webhooks/voice/transfer') ? $call->originalCallId() : $call->callId();

        return ! $request->is('webhooks/voice/inbound', 'webhooks/voice/outgoing')
            && Call::query()->where('carrier_sid', (string) $id)->value('decision') === DialDecision::EMERGENCY;
    }

    private function fromPhone(string $username): ?int
    {
        if (preg_match('/^user-(\d+)$/', $username, $m)) {
            return User::query()->whereKey((int) $m[1])->value('tenant_id');
        }

        return preg_match('/^u\d+-[a-f0-9]{6}$/', $username)
            ? DeskPhone::query()->where('sip_username', $username)->value('tenant_id')
            : null;
    }

    /**
     * Any company could add a trunk at an address it controls and send calls
     * "to" another company's number, which would then forward them at that
     * company's expense. So the call must come from the number's own trunk.
     */
    private function fromTrunkCall(CallRequest $call, Request $request): ?int
    {
        $number = null;
        foreach ([$call->to(), (string) $request->input('to_user')] as $address) {
            $e164 = Dialplan::numberFromCarrierAddress($address);
            if ($e164 && ($number = PhoneNumber::query()->with('trunk')->firstWhere('e164', $e164))) {
                break;
            }
        }
        if (! $number || $number->trunk?->tenant_id !== $number->tenant_id) {
            return null;
        }

        $source = (string) $request->input('source');
        $allowed = array_map(fn (array $e) => $e['ip'].'/'.(int) $e['prefix'], $number->trunk?->inbound_ips ?? []);
        if (! filter_var($source, FILTER_VALIDATE_IP) || ! $allowed || ! IpUtils::checkIp($source, $allowed)) {
            Log::warning('Refused a trunk call that did not come from the dialled number\'s own trunk.', [
                'number' => $number->e164, 'source' => substr($source, 0, 45), 'trunk_id' => $number->sip_trunk_id,
            ]);

            return null;
        }

        if (! $this->provenWhenShared($number, $source, (string) $request->input('gateway'))) {
            Log::warning('Refused a trunk call from a provider address another company also uses, not sent to the number\'s own trunk registration.', [
                'number' => $number->e164, 'trunk_id' => $number->sip_trunk_id,
            ]);

            return null;
        }

        return $number->tenant_id;
    }

    /**
     * Two companies with the same provider share its addresses, so the address
     * alone can't say whose trunk a call came in on: either could send a call
     * "to" the other's number. Then the call must arrive at the trunk's own
     * registration (gw+<gateway name>, a secret only that provider was given).
     * Our own carrier only delivers our numbers, so its trunks are trusted.
     */
    private function provenWhenShared(PhoneNumber $number, string $source, string $gateway): bool
    {
        $trunk = $number->trunk;
        if (! $this->tenancy->enabled() || $trunk->supplied_by_operator) {
            return true;
        }

        $shared = SipTrunk::query()->where('tenant_id', '!=', $number->tenant_id)->where('status', 'active')->get(['inbound_ips'])
            ->contains(fn (SipTrunk $other) => IpUtils::checkIp($source, array_map(fn (array $e) => $e['ip'].'/'.(int) $e['prefix'], $other->inbound_ips ?? [])));
        if (! $shared) {
            return true;
        }

        return $trunk->registers() && collect($trunk->gatewayNames())->contains(fn (string $name) => hash_equals($name, $gateway));
    }

    /** A call's own log, or for a transferred leg, the transfer logged under it. */
    private function fromCallId(?string $id): ?int
    {
        return $id ? Call::query()->where('carrier_sid', $id)->orWhere('parent_sid', $id)->value('tenant_id') : null;
    }
}
