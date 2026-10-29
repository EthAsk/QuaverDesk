<?php

namespace App\Http\Controllers\Webhooks;

use App\Domain\Calls\CallScript;
use App\Domain\Calls\DialTarget;
use App\Domain\Calls\OutboundScript;
use App\Domain\Licensing\Feature;
use App\Domain\Routing\Destination;
use App\Domain\Routing\InboundRouter;
use App\Domain\Routing\RoutingContext;
use App\Domain\Telephony\DialDecision;
use App\Domain\Telephony\Dialplan;
use App\Http\Controllers\Controller;
use App\Jobs\StoreRecording;
use App\Models\Acknowledgement;
use App\Models\Call;
use App\Models\DeskPhone;
use App\Models\Mailbox;
use App\Models\PhoneNumber;
use App\Models\Recording;
use App\Models\RingGroup;
use App\Models\Setting;
use App\Models\SipTrunk;
use App\Models\Sound;
use App\Models\User;
use App\Support\CallerId;
use App\Support\LicenceManager;
use App\Support\CallLimits;
use App\Support\RecordingPolicy;
use App\Support\RouteCallbackUrls;
use App\Voice\CallRequest;
use App\Voice\ParkingLot;
use App\Voice\VoiceServer;
use App\Voice\VoiceUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Called by the voice server (shared secret checked by middleware), never by browsers.
 *
 * After a call ends FreeSWITCH sends one last request ("exiting") to whichever
 * URL the call was on. Those only close the call log; any recording they carry
 * is handled by the recording endpoints.
 */
class VoiceController extends Controller
{
    public const MSG_NOT_IN_SERVICE = 'Sorry, this number is not in service.';
    public const MSG_PICK_UP_HOW = 'To pick up a parked call, dial star star and the slot number.';
    public const MSG_CANT_TRANSFER = 'Sorry, this call cannot be transferred.';
    public const MSG_CONFIRM_MOBILE = 'Hello. This call is to confirm your mobile for your work phone, so your calls can ring it too. To confirm, press 1.';

    public function __construct(
        private readonly VoiceServer $voice,
        private readonly LicenceManager $licences,
        private readonly InboundRouter $router,
        private readonly RecordingPolicy $recordingPolicy,
        private readonly CallLimits $limits,
        private readonly ParkingLot $parking,
    ) {
    }

    /** A softphone or desk phone placed a call; decide where it goes. */
    public function outgoing(Request $request): Response
    {
        $call = new CallRequest($request);
        if ($call->exiting()) {
            return $this->ended($call);
        }

        $caller = $this->userFromIdentity($call->from());
        $to = $call->to();

        if ($caller === null || ! Acknowledgement::currentAccepted()) {
            $decision = Dialplan::isEmergency($to)
                ? Dialplan::noEmergencyService()
                : DialDecision::reject('This phone is not set up to make calls.');
        } else {
            $decision = $this->decide($caller, $to);
        }

        // The trunk an outside call will use, recorded for usage (and billing, on trunks we supply).
        $trunk = in_array($decision->type, [DialDecision::NUMBER, DialDecision::EMERGENCY], true) ? SipTrunk::defaultOutbound() : null;

        $this->log($call, [
            'user_id' => $caller?->id,
            'sip_trunk_id' => $trunk?->id,
            'direction' => in_array($decision->type, [DialDecision::CLIENT, DialDecision::RING_GROUP, DialDecision::PARK], true) ? 'internal' : 'outbound',
            'from' => $caller?->carrierIdentity(),
            'to' => $decision->target ?? substr($to, 0, 64),
            'decision' => $decision->type,
            'status' => $decision->type === DialDecision::REJECT ? 'rejected' : 'initiated',
        ]);

        return $this->reply(match ($decision->type) {
            DialDecision::RING_GROUP => $this->router->route(new Destination(Destination::RING_GROUP, (int) $decision->target), new RoutingContext()),
            DialDecision::PARK => $this->pickUp($decision),
            default => $this->dialOut($decision, $trunk, $caller),
        });
    }

    /**
     * A phone transferred a call (or parked it, or a parked call is coming
     * back). The call goes wherever the transferring person could have called
     * themselves, under the same rules and limits; if it can't, it rings them
     * again rather than being dropped.
     */
    public function transfer(Request $request): Response
    {
        $call = new CallRequest($request);
        if ($call->exiting()) {
            return $this->ended($call);
        }

        $original = Call::query()->where('carrier_sid', $call->originalCallId())->first();

        // An emergency call is never transferred or parked away: it goes straight back to the caller's phone.
        if ($original?->decision === DialDecision::EMERGENCY && preg_match('/^user-\d+$/', (string) $original->from)) {
            return $this->reply((new CallScript())->dial([DialTarget::client($original->from)], ['timeout' => 60]));
        }

        $by = $this->userFromIdentity($call->transferredBy());
        if ($by === null) {
            return $this->reply((new CallScript())->say(self::MSG_CANT_TRANSFER)->hangup());
        }

        $decision = $this->decide($by, $call->to());
        $trunk = in_array($decision->type, [DialDecision::NUMBER, DialDecision::EMERGENCY], true) ? SipTrunk::defaultOutbound() : null;
        $slot = $decision->type === DialDecision::PARK ? $this->parking->slotFor($decision->target !== null ? (int) $decision->target : null) : null;

        if ($id = $call->callId()) {
            Call::query()->create([
                'carrier_sid' => (string) Str::uuid(),
                'parent_sid' => $id,
                'leg' => Call::LEG_TRANSFER,
                'user_id' => $by->id,
                'sip_trunk_id' => $trunk?->id,
                'direction' => $trunk ? 'outbound' : 'internal',
                'from' => $by->carrierIdentity(),
                'to' => $decision->type === DialDecision::PARK ? '**'.$slot : ($decision->target ?? substr($call->to(), 0, 64)),
                'decision' => $decision->type,
                'status' => $decision->type === DialDecision::REJECT ? 'rejected' : 'initiated',
            ]);
        }

        $script = match (true) {
            $decision->type === DialDecision::PARK && $slot !== null => (new CallScript())->park($this->parking->name(), $slot, [
                'holdMusic' => Sound::holdMusic()?->url(),
                'returnTo' => $by->carrierIdentity(),
                'waitSeconds' => ParkingLot::WAIT_SECONDS,
            ]),
            $decision->type === DialDecision::CLIENT => $this->router->route(new Destination(Destination::USER, (int) substr((string) $decision->target, 5)), new RoutingContext()),
            $decision->type === DialDecision::RING_GROUP => $this->router->route(new Destination(Destination::RING_GROUP, (int) $decision->target), new RoutingContext()),
            in_array($decision->type, [DialDecision::NUMBER, DialDecision::EMERGENCY], true) => $this->dialOut($decision, $trunk, $by),
            // Nowhere it can go (or no free parking slot): back to the person who transferred it.
            default => $this->router->route(new Destination(Destination::USER, $by->id), new RoutingContext()),
        };

        return $this->reply($script);
    }

    /** Someone called one of our numbers through a SIP trunk. */
    public function inbound(Request $request): Response
    {
        $call = new CallRequest($request);
        if ($call->exiting()) {
            return $this->ended($call);
        }

        // Providers put the dialled number in the request line or, for registration trunks, the To header.
        $e164 = Dialplan::numberFromCarrierAddress($call->to());
        $number = $e164 ? PhoneNumber::query()->firstWhere('e164', $e164) : null;
        if ($number === null && ($toUser = Dialplan::numberFromCarrierAddress((string) $request->input('to_user')))) {
            $e164 = $toUser;
            $number = PhoneNumber::query()->firstWhere('e164', $e164);
        }

        $this->log($call, [
            'sip_trunk_id' => $number?->sip_trunk_id,
            'direction' => 'inbound',
            'from' => $call->from(),
            'to' => $e164 ?? $call->to(),
            'decision' => $number ? Destination::parse($number->destination)?->type : 'reject',
            'status' => 'ringing',
        ]);

        if ($number === null || ! Acknowledgement::currentAccepted() || ! $this->licences->allows(Feature::PhoneNumbers)) {
            return $this->reply((new CallScript())->say(self::MSG_NOT_IN_SERVICE)->hangup());
        }

        $record = $number->record_calls && $this->recordingPolicy->canRecordCalls();

        return $this->reply($this->router->forNumber(
            Destination::parse($number->destination) ?? Destination::hangup(),
            $number->schedule(),
            Destination::parse($number->closed_destination),
            now()->toDateTimeImmutable(),
            $record,
            $record ? $this->recordingPolicy->disclosure() : null,
        ));
    }

    /**
     * A call to someone's mobile to confirm it is theirs (follow-me). Pressing 1
     * confirms it, as long as it is still the number they gave.
     */
    public function confirmMobile(Request $request): Response
    {
        $call = new CallRequest($request);
        if ($call->exiting()) {
            return $this->ended($call);
        }

        $log = Call::query()->where('carrier_sid', (string) $call->callId())->first();
        $user = $log?->user;
        if ($user === null || $user->mobile !== $log->to) {
            return $this->reply((new CallScript())->hangup());
        }

        if ($call->digits() === null && ! $request->query('asked')) {
            Call::query()->whereKey($log->id)->update(['status' => 'completed']);

            return $this->reply((new CallScript())->gather(self::MSG_CONFIRM_MOBILE, [
                'numDigits' => 1, 'timeout' => 10, 'action' => VoiceUrl::to('webhooks.voice.confirm-mobile', ['asked' => 1]),
            ]));
        }

        if ($call->digits() !== '1') {
            return $this->reply((new CallScript())->say('Your mobile was not confirmed. Goodbye.')->hangup());
        }

        $user->forceFill(['mobile_verified_at' => now()])->save();

        return $this->reply((new CallScript())->say('Thank you. Your mobile is confirmed. Goodbye.')->hangup());
    }

    /** Continue routing (IVR replays, sequential ring groups, fallbacks). */
    public function route(Request $request): Response
    {
        $call = new CallRequest($request);
        if ($call->exiting()) {
            return $this->ended($call);
        }

        $to = Destination::parse($request->query('to')) ?? Destination::hangup();

        return $this->reply($this->router->route($to, $this->context($request)));
    }

    public function afterDial(Request $request): Response
    {
        $call = new CallRequest($request);
        if ($call->exiting()) {
            return $this->ended($call);
        }

        $status = $call->dialStatus();
        if ($id = $call->callId()) {
            // A transferred call's outcome belongs to the transfer, not to the call as it first arrived.
            $transfer = Call::query()->where('parent_sid', $id)->where('leg', Call::LEG_TRANSFER)->where('status', 'initiated')->update(['status' => $status]);
            if ($transfer === 0) {
                Call::query()->where('carrier_sid', $id)->where('status', '!=', 'rejected')->update(['status' => $status]);
            }
            $this->settleMobileLegs($id, $call->answeredLeg());
        }

        $next = Destination::parse($request->query('next')) ?? Destination::hangup();

        return $this->reply($this->router->afterDial($status, $next, $this->context($request)));
    }

    public function ivrChoice(Request $request): Response
    {
        $call = new CallRequest($request);
        if ($call->exiting()) {
            return $this->ended($call);
        }

        $ivr = Destination::parse($request->query('ivr'));
        if ($ivr === null || $ivr->type !== Destination::IVR) {
            return $this->reply((new CallScript())->say(InboundRouter::MSG_UNAVAILABLE)->hangup());
        }

        return $this->reply($this->router->ivrChoice($ivr, $call->digits(), $this->context($request)));
    }

    /** The caller left (or didn't leave) a voicemail; the audio comes with this request. */
    public function voicemailSaved(Request $request): Response
    {
        $call = new CallRequest($request);
        $box = Destination::parse($request->query('box'));
        $mailbox = ($box && $box->type === Destination::VOICEMAIL) ? Mailbox::query()->find($box->id) : null;

        $saved = $mailbox && $this->saveRecording($call, Recording::VOICEMAIL, $mailbox->id);

        if ($call->exiting()) {
            return $this->ended($call);
        }

        return $this->reply(InboundRouter::voicemailDone($saved));
    }

    /** A recorded call ended; the audio comes with this request. */
    public function recordingSaved(Request $request): Response
    {
        $call = new CallRequest($request);
        if ($this->licences->allows(Feature::Recording)) {
            $this->saveRecording($call, Recording::CALL, null);
        }

        return $call->exiting() ? $this->ended($call) : $this->reply((new CallScript())->hangup());
    }

    private function saveRecording(CallRequest $call, string $kind, ?int $mailboxId): bool
    {
        $file = $call->recording();
        $id = $call->callId();
        if ($file === null || $id === null) {
            return false;
        }

        $seconds = self::wavSeconds((string) $file->getRealPath());
        if ($seconds < 1) {
            return false;
        }

        $log = Call::query()->where('carrier_sid', $id)->first();
        $path = $file->storeAs('incoming', $id.'-'.$kind.'-'.bin2hex(random_bytes(4)).'.wav', 'local');

        $recording = Recording::query()->firstOrCreate(
            ['carrier_sid' => $id.'-'.$kind],
            [
                'kind' => $kind,
                'call_id' => $log?->id,
                'mailbox_id' => $mailboxId,
                'upload_path' => $path,
                'from' => $log?->from,
                'to' => $log?->to,
                'duration_seconds' => $seconds,
                'status' => 'pending',
            ],
        );

        if ($recording->wasRecentlyCreated) {
            StoreRecording::dispatch($recording->id);
        } else {
            Storage::disk('local')->delete($path); // a retried upload of a recording we already have
        }

        return true;
    }

    /** Length of a PCM WAV file from its header; 0 if it isn't one. */
    public static function wavSeconds(string $path): int
    {
        $h = @file_get_contents($path, false, null, 0, 44);
        if ($h === false || strlen($h) < 44 || substr($h, 0, 4) !== 'RIFF' || substr($h, 8, 4) !== 'WAVE') {
            return 0;
        }
        $byteRate = unpack('V', substr($h, 28, 4))[1];

        return $byteRate > 0 ? (int) round(max(0, filesize($path) - 44) / $byteRate) : 0;
    }

    private function ended(CallRequest $call): Response
    {
        if ($id = $call->callId()) {
            $this->settleMobileLegs($id, $call->answeredLeg());
            $logs = Call::query()->whereNull('duration_seconds')
                ->where(fn ($q) => $q->where('carrier_sid', $id)->orWhere(fn ($q) => $q->where('parent_sid', $id)->where('leg', Call::LEG_TRANSFER)))
                ->get();
            foreach ($logs as $log) {
                $log->forceFill([
                    'status' => in_array($log->status, ['initiated', 'ringing'], true) ? 'ended' : $log->status,
                    'duration_seconds' => max(0, (int) $log->created_at->diffInSeconds(now(), true)),
                ])->save();
            }
        }

        return response('', 204);
    }

    /**
     * Once a dial is over, each ring to a person's mobile is settled: the one
     * that answered (if any) counts its time, from when it started ringing; the
     * rest count as unanswered outside calls.
     */
    private function settleMobileLegs(string $callId, ?string $answered): void
    {
        $legs = Call::query()->where('parent_sid', $callId)->where('leg', Call::LEG_FOLLOW_ME)->whereNull('duration_seconds')->get();
        foreach ($legs as $leg) {
            $won = $answered !== null && hash_equals($leg->carrier_sid, $answered);
            $leg->forceFill([
                'status' => $won ? 'completed' : 'no-answer',
                'duration_seconds' => $won ? max(0, (int) $leg->created_at->diffInSeconds(now(), true)) : 0,
            ])->save();
        }
    }

    /**
     * Rings to people's mobiles (follow-me) are outside calls: each one is
     * logged before it is dialled, under the id the voice server will give it,
     * so it counts towards the toll-fraud limits while it rings.
     */
    private function logMobileLegs(CallScript $script): CallScript
    {
        $parent = (new CallRequest(request()))->callId();
        if ($parent === null) {
            return $script;
        }

        return $script->mapTargets(function (DialTarget $target) use ($parent) {
            if ($target->followMe === null) {
                return $target;
            }
            $leg = Call::query()->create([
                'carrier_sid' => (string) Str::uuid(),
                'parent_sid' => $parent,
                'leg' => Call::LEG_FOLLOW_ME,
                'user_id' => $target->followMe->userId,
                'sip_trunk_id' => $target->followMe->trunkId,
                'direction' => 'outbound',
                'from' => $target->followMe->callerId,
                'to' => $target->value,
                'decision' => DialDecision::NUMBER,
                'status' => 'ringing',
            ]);

            return $target->withLegId($leg->carrier_sid);
        });
    }

    /**
     * What a person dialling (or transferring a call to) $to may reach. Colleagues
     * only: someone's identity (user-12, *12) from another company, or nobody, isn't
     * dialled. Toll-fraud limits apply (never to emergency calls).
     */
    private function decide(User $caller, string $to): DialDecision
    {
        $decision = (new Dialplan(
            allowedInternationalPrefixes: Setting::get('calling.international_prefixes', []),
            premiumAllowed: (bool) Setting::get('calling.premium_allowed', false),
            extensions: fn (string $ext) => User::query()->firstWhere('extension', $ext)?->carrierIdentity()
                ?? (($group = RingGroup::query()->where('extension', $ext)->value('id')) ? 'ring_group:'.$group : null),
        ))->decide($to);

        if ($decision->type === DialDecision::CLIENT && ! User::query()->whereKey((int) substr((string) $decision->target, 5))->exists()) {
            return DialDecision::reject('That extension is not in use.');
        }

        return $this->limits->refusal($caller, $decision) ?? $decision;
    }

    private function dialOut(DialDecision $decision, ?SipTrunk $trunk, ?User $caller): CallScript
    {
        $recording = $this->recordingPolicy->recordOutbound()
            ? ['status' => VoiceUrl::to('webhooks.voice.recording-saved', []), 'whisper' => $this->recordingPolicy->disclosure()]
            : null;

        return OutboundScript::fromDecision(
            $decision,
            CallerId::default(),
            $trunk?->dialTrunk(),
            $recording,
            VoiceUrl::to('webhooks.voice.after-dial', ['next' => (string) Destination::hangup()]),
            $caller ? $this->limits->maxSeconds($caller) : null,
        );
    }

    /** A phone dialled ** and a slot: it is connected to the call waiting there. */
    private function pickUp(DialDecision $decision): CallScript
    {
        $slot = $decision->target !== null ? (int) $decision->target : null;

        return match (true) {
            $slot === null => (new CallScript())->say(self::MSG_PICK_UP_HOW)->hangup(),
            ! $this->parking->isOccupied($slot) => (new CallScript())->say("There is no call parked on {$slot}.")->hangup(),
            default => (new CallScript())->park($this->parking->name(), $slot),
        };
    }

    private function reply(CallScript $script): Response
    {
        $script = $this->logMobileLegs($script);

        return response($this->voice->render($script), 200, ['Content-Type' => $this->voice->responseContentType()]);
    }

    /** @param array<string, mixed> $attributes */
    private function log(CallRequest $call, array $attributes): void
    {
        if ($id = $call->callId()) {
            Call::query()->firstOrCreate(['carrier_sid' => $id], $attributes);
        }
    }

    private function context(Request $request): RoutingContext
    {
        return RouteCallbackUrls::contextFrom($request->query('hop'), $request->query('rec'));
    }

    /** The softphone (user-12) or desk phone (u12-ab12cd) that is calling, as authenticated by the voice server. */
    private function userFromIdentity(string $from): ?User
    {
        if (preg_match('/^user-(\d+)$/', $from, $m)) {
            return User::query()->find((int) $m[1]);
        }

        if (preg_match('/^u\d+-[a-f0-9]{6}$/', $from) && $this->licences->allows(Feature::DeskPhones)) {
            return DeskPhone::query()->where('sip_username', $from)->first()?->user;
        }

        return null;
    }
}
