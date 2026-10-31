<?php

namespace App\Http\Controllers\Webhooks;

use App\Domain\Calls\CallScript;
use App\Domain\Telephony\DialDecision;
use App\Http\Controllers\Controller;
use App\Models\Call;
use App\Models\Recording;
use App\Models\User;
use App\Voice\CallRequest;
use App\Voice\PhoneSounds;
use App\Voice\VoicemailMenu;
use App\Voice\VoiceServer;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Key presses and recordings from a *00 call (see VoicemailMenu). Every step
 * checks the call is a *00 call and the box is one the caller is a member of,
 * so the query string can't open someone else's voicemail.
 */
class VoicemailMenuController extends Controller
{
    public function __construct(
        private readonly VoicemailMenu $menu,
        private readonly VoiceServer $voice,
    ) {
    }

    public function menu(Request $request): Response
    {
        $call = new CallRequest($request);
        if ($call->exiting()) {
            return app(VoiceController::class)->ended($call);
        }
        $caller = $this->caller($call);
        if ($caller === null) {
            return $this->reply((new CallScript())->hangup());
        }

        $box = $request->query('box') !== null ? $this->menu->boxes($caller)->firstWhere('id', (int) $request->query('box')) : null;
        $message = $box && $request->query('message') !== null
            ? $this->menu->messages($box)->firstWhere('id', (int) $request->query('message'))
            : null;

        return $this->reply(match ((string) $request->query('step')) {
            'choose' => $this->menu->choose($caller, $call->digits()),
            'box' => $box ? $this->menu->boxChoice($caller, $box, $call->digits()) : $this->menu->start($caller),
            'message' => $box && $message ? $this->menu->messageChoice($box, $message, $call->digits()) : $this->menu->start($caller),
            default => $this->menu->start($caller),
        });
    }

    /** A new greeting recorded under *00: saved as a sound and set as the box's greeting. */
    public function greetingRecorded(Request $request, PhoneSounds $sounds): Response
    {
        $call = new CallRequest($request);
        $caller = $this->caller($call);
        $box = $caller ? $this->menu->boxes($caller)->firstWhere('id', (int) $request->query('box')) : null;

        $sound = $box && VoicemailMenu::mayRecordGreeting($caller, $box)
            ? $sounds->save($call, 'Greeting for '.$box->name.', recorded on the phone '.now()->format('j M, H:i'))
            : null;
        $sound && $box->forceFill(['greeting_sound_id' => $sound->id])->save();

        if ($call->exiting()) {
            return app(VoiceController::class)->ended($call);
        }

        return $this->reply($this->menu->greetingRecorded($sound?->url()));
    }

    /** Who is on this *00 call, from our own log of it (never from the request). */
    private function caller(CallRequest $call): ?User
    {
        $log = Call::query()->where('carrier_sid', (string) $call->callId())->first();

        return $log?->decision === DialDecision::VOICEMAIL ? $log->user : null;
    }

    private function reply(CallScript $script): Response
    {
        return response($this->voice->render($script), 200, ['Content-Type' => $this->voice->responseContentType()]);
    }
}
