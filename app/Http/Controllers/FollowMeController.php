<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\MobileConfirmation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
            $mobile = MobileConfirmation::normalise($data['mobile']);
            if ($mobile === null) {
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

    /** Calls the person's mobile; pressing 1 proves it is theirs. */
    public function verify(Request $request, MobileConfirmation $confirmation): RedirectResponse
    {
        $user = $request->user();
        if ($user->mobile === null || $user->mobile_verified_at !== null) {
            return back();
        }

        if ($problem = $confirmation->call($user)) {
            throw ValidationException::withMessages(['mobile' => $problem]);
        }

        return back()->with('status', 'Calling your mobile now. Answer it and press 1.');
    }
}
