<?php

namespace App\Support;

use App\Models\PhoneNumber;
use App\Models\Setting;
use App\Models\SipTrunk;

final class CallerId
{
    /** The number shown on outside calls. The install-wide default is the operator's own; hosted companies only show theirs. */
    public static function default(): ?string
    {
        return Setting::get('calling.caller_id') ?: (config('tenancy.enabled') ? null : config('voip.caller_id'));
    }

    /**
     * The number shown on a 999/112 call: the usual one, or else one of the
     * company's own numbers (on the trunk the call uses first), so an
     * emergency call is never refused for want of a caller ID.
     */
    public static function forEmergency(?SipTrunk $trunk): ?string
    {
        return self::default()
            ?? ($trunk ? PhoneNumber::query()->where('sip_trunk_id', $trunk->id)->orderBy('id')->value('e164') : null)
            ?? PhoneNumber::query()->orderBy('id')->value('e164');
    }
}
