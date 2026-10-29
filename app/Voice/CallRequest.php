<?php

namespace App\Voice;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * Reads a webhook from FreeSWITCH (mod_httapi). Every request carries the call's
 * id and the caller profile; some carry key presses, a dial outcome or a recording.
 */
final class CallRequest
{
    public function __construct(private readonly Request $request)
    {
    }

    public function callId(): ?string
    {
        $id = (string) $this->request->input('session_id');

        return preg_match('/^[0-9a-f-]{36}$/i', $id) ? strtolower($id) : null;
    }

    /**
     * The call's first leg, which its log is kept under. On a transferred
     * call, the voice server's dialplan says which one that was.
     */
    public function originalCallId(): ?string
    {
        $id = (string) $this->request->input('call');

        return preg_match('/^[0-9a-f-]{36}$/i', $id) ? strtolower($id) : $this->callId();
    }

    /** The phone that transferred this call (user-12, u12-ab12cd), as our dialplan recorded it. */
    public function transferredBy(): string
    {
        return substr((string) $this->request->input('by'), 0, 64);
    }

    /** Which leg of the last dial answered, when the app asked to be told. */
    public function answeredLeg(): ?string
    {
        $id = (string) $this->request->input('last_bridge_to');

        return preg_match('/^[0-9a-f-]{36}$/i', $id) ? strtolower($id) : null;
    }

    /**
     * Who is calling: for our own phones the authenticated SIP username
     * (user-12, u12-ab12cd), passed by our dialplan; otherwise caller ID.
     */
    public function from(): string
    {
        return substr((string) ($this->request->input('from') ?: $this->request->input('Caller-Caller-ID-Number')), 0, 64);
    }

    /** The number or extension dialled. */
    public function to(): string
    {
        return substr((string) $this->request->input('Caller-Destination-Number'), 0, 64);
    }

    public function digits(): ?string
    {
        $d = $this->request->input('digits');

        return is_string($d) && preg_match('/^[0-9*#]{1,32}$/', $d) ? $d : null;
    }

    /** True on the final request FreeSWITCH sends after the call has ended. */
    public function exiting(): bool
    {
        return $this->request->input('exiting') === 'true';
    }

    /** How the last dial ended: completed, busy, no-answer, canceled or failed. */
    public function dialStatus(): string
    {
        return match ((string) $this->request->input('originate_disposition')) {
            'SUCCESS' => 'completed',
            'USER_BUSY', 'CALL_REJECTED' => 'busy',
            // A colleague who is not signed in counts as a missed call.
            'NO_ANSWER', 'NO_USER_RESPONSE', 'ALLOTTED_TIMEOUT', 'USER_NOT_REGISTERED', 'SUBSCRIBER_ABSENT' => 'no-answer',
            'ORIGINATOR_CANCEL' => 'canceled',
            default => 'failed',
        };
    }

    public function recording(): ?UploadedFile
    {
        $file = $this->request->file('recording');

        return $file instanceof UploadedFile && $file->isValid() && $file->getSize() > 44 ? $file : null;
    }
}
