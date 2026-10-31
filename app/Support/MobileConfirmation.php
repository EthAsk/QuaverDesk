<?php

namespace App\Support;

use App\Domain\Telephony\DialDecision;
use App\Domain\Telephony\Dialplan;
use App\Models\Call;
use App\Models\SipTrunk;
use App\Models\User;
use App\Voice\VoiceServer;
use App\Voice\VoiceUrl;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Calls a person's mobile; pressing 1 proves it is theirs (see
 * VoiceController::confirmMobile). It's an outside call like any other.
 */
class MobileConfirmation
{
    public function __construct(
        private readonly CallLimits $limits,
        private readonly VoiceServer $voice,
    ) {
    }

    /** Places the call. Returns why it couldn't be made, or null once it's ringing. */
    public function call(User $user): ?string
    {
        $trunk = SipTrunk::defaultOutbound();
        $callerId = CallerId::default();
        $problem = match (true) {
            $trunk === null || $callerId === null => 'This phone system has no phone line for outside calls yet.',
            default => $this->limits->refusal($user, DialDecision::number($user->mobile))?->message,
        };
        if ($problem !== null) {
            return $problem;
        }

        $call = Call::query()->create([
            'carrier_sid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'sip_trunk_id' => $trunk->id,
            'direction' => 'outbound',
            'from' => $callerId,
            'to' => $user->mobile,
            'decision' => DialDecision::NUMBER,
            'status' => 'initiated',
        ]);

        try {
            $this->voice->callNumber($user->mobile, $trunk->gatewayNames()[0], $callerId, $call->carrier_sid, VoiceUrl::to('webhooks.voice.confirm-mobile', []));
        } catch (RuntimeException $e) {
            Log::error('Could not call a mobile to confirm it.', ['error' => $e->getMessage()]);
            $call->forceFill(['status' => 'failed', 'duration_seconds' => 0])->save();

            return 'We couldn\'t call the mobile just now. Please try again later.';
        }

        return null;
    }

    /** A UK mobile in E.164 (+447…), or null if the input isn't one. */
    public static function normalise(string $input): ?string
    {
        $mobile = Dialplan::normaliseUk(preg_replace('/[\s().-]/', '', $input) ?? '');

        return $mobile !== null && str_starts_with($mobile, '+447') ? $mobile : null;
    }
}
