<?php

namespace App\Domain\Routing;

/** Builds the absolute URLs the voice server calls back as a call moves through routing. */
interface CallbackUrls
{
    /** Continue routing at a destination. */
    public function route(Destination $destination, RoutingContext $ctx): string;

    /** After a <Dial> ends: hang up if answered, otherwise go to $next. */
    public function afterDial(Destination $next, RoutingContext $ctx): string;

    /** Where an IVR sends the caller's key press. */
    public function ivrChoice(Destination $ivr, RoutingContext $ctx): string;

    /** Receives a voicemail recording, then finishes the call. */
    public function voicemailSaved(Destination $mailbox): string;

    /** Called when a call recording is saved. */
    public function recordingSaved(): string;
}
