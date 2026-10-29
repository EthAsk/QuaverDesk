<?php

namespace App\Support;

use App\Models\Setting;

final class CallerId
{
    /** The number shown on outside calls. The install-wide default is the operator's own; hosted companies only show theirs. */
    public static function default(): ?string
    {
        return Setting::get('calling.caller_id') ?: (config('tenancy.enabled') ? null : config('voip.caller_id'));
    }
}
