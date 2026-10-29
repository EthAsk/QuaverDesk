<?php

namespace App\Http\Controllers;

use App\Domain\Telephony\DialDecision;
use App\Domain\Telephony\Dialplan;
use App\Models\Call;
use App\Models\SipTrunk;
use App\Models\User;
use App\Support\CallerId;
use App\Support\CallLimits;
use App\Voice\VoiceServer;
use App\Voice\VoiceUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Follow-me: a person's calls also ring their own mobile. The mobile
 * must be proved theirs first: we call it and they press 1.
 */
class FollowMeController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'mobile' => ['nullable', 'string', 'max:20'],
            'follow_me' => ['required', Rule::in([User::FOLLOW_ME_OFF, User::FOLLOW_ME_TOGETHER, User::FOLLOW_ME_AFTER])],
            'follow_me_delay' => ['required', 'integer', 'min:5', 'max:20'],
        ]);

        $mobile = null;
        if (filled($data['mobile'] ?? null)) {
            $mobile = Dialplan::normaliseUk(preg_replace('/[\s().-]/', '', $data['mobile']) ?? '');
            if ($mobile === null || ! str_starts_with($mobile, '+447')) {
                throw ValidationException::withMessages(['mobile' => 'Enter a UK mobile number, such as 07700 900123.']);
            }
        }

        if ($mobile !== $user->mobile) {
            $user->mobile = $mobile;
            $user->mobile_verified_at = null; // a new number has to be confirmed again
        }
        $user->follow_me = $mobile ? $data['follow_me'] : User::FOLLOW_ME_OFF;
        $user->follow_me_delay = (int) $data['follow_me_delay'];
        $user->save();

        return back()->with('status', match (true) {
            $mobile === null => 'Your mobile has been removed.',
            $user->mobile_verified_at === null => 'Saved. Now confirm your mobile: we\'ll call it and you press 1.',
            default => 'Saved.',
        });
    }

    /** Calls the person's mobile; pressing 1 proves it is theirs. It's an outside call like any other. */
    public function verify(Request $request, CallLimits $limits, VoiceServer $voice): RedirectResponse
    {
        $user = $request->user();
        if ($user->mobile === null || $user->mobile_verified_at !== null) {
            return back();
        }

        $trunk = SipTrunk::defaultOutbound();
        $callerId = CallerId::default();
        $problem = match (true) {
            $trunk === null || $callerId === null => 'This phone system has no phone line for outside calls yet. Ask your administrator.',
            default => $limits->refusal($user, DialDecision::number($user->mobile))?->message,
        };
        if ($problem !== null) {
            throw ValidationException::withMessages(['mobile' => $problem]);
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
            $voice->callNumber($user->mobile, $trunk->gatewayNames()[0], $callerId, $call->carrier_sid, VoiceUrl::to('webhooks.voice.confirm-mobile', []));
        } catch (RuntimeException $e) {
            Log::error('Could not call a mobile to confirm it.', ['error' => $e->getMessage()]);
            $call->forceFill(['status' => 'failed', 'duration_seconds' => 0])->save();

            throw ValidationException::withMessages(['mobile' => 'We couldn\'t call your mobile just now. Please try again later.']);
        }

        return back()->with('status', 'Calling your mobile now. Answer it and press 1.');
    }
}
