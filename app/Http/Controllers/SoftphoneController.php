<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Voice\ParkingLot;
use App\Voice\VoiceServer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use RuntimeException;

class SoftphoneController extends Controller
{
    /** The phone page in the app: status, settings and a way to pop the phone out. */
    public function show(Request $request): View
    {
        return view('softphone', self::data($request->user()));
    }

    /** The phone in its own small window (or installed app) that stays open in the background. */
    public function phone(Request $request): View
    {
        return view('phone', self::data($request->user()));
    }

    /** What the phone shows: who it can call by name. */
    public static function data(User $user): array
    {
        return [
            'colleagues' => User::query()->whereKeyNot($user->id)->orderBy('name')->get(['id', 'name', 'extension']),
        ];
    }

    /** A free parking slot for the phone to park its call on (it then transfers the call to **slot). */
    public function park(ParkingLot $lot): JsonResponse
    {
        $slot = $lot->reserve();

        return $slot === null
            ? response()->json(['message' => 'Every parking space is in use.'], 409)
            : response()->json(['slot' => $slot]);
    }

    /** Sign-in details for the browser softphone (sent only to the signed-in user, over HTTPS). */
    public function token(Request $request, VoiceServer $voice): JsonResponse
    {
        try {
            $settings = $voice->softphoneSettings($request->user());
        } catch (RuntimeException $e) {
            Log::error('Softphone token failed.', ['error' => $e->getMessage()]);

            return response()->json(['message' => 'The phone service is not configured yet. Ask your administrator.'], 503);
        }

        return response()->json($settings + ['identity' => $request->user()->carrierIdentity()])
            ->header('Cache-Control', 'no-store');
    }
}
