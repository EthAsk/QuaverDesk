<?php

namespace App\Jobs;

use App\Domain\Calls\OutboundScript;
use App\Domain\Routing\InboundRouter;
use App\Http\Controllers\Webhooks\VoiceController;
use App\Jobs\Middleware\TakeTurns;
use App\Models\IvrMenu;
use App\Models\Mailbox;
use App\Support\RecordingPolicy;
use App\Voice\Prompts;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Makes prompt audio ahead of time, so the first caller to hear a greeting doesn't wait for it. */
class WarmPrompts implements ShouldQueue
{
    use Queueable;

    public int $maxExceptions = 3;

    /** @param list<string>|null $texts null: every prompt the system can play */
    public function __construct(public readonly ?array $texts = null)
    {
    }

    public function middleware(): array
    {
        return [new TakeTurns()];
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addDay();
    }

    public function handle(Prompts $prompts, RecordingPolicy $recording): void
    {
        if (! $prompts->makesFiles()) {
            return;
        }

        foreach ($this->texts ?? self::all($recording) as $text) {
            $prompts->warm($text);
        }
    }

    /** @return list<string> */
    public static function all(RecordingPolicy $recording): array
    {
        return array_values(array_unique(array_filter([
            VoiceController::MSG_NOT_IN_SERVICE,
            OutboundScript::MSG_NO_CALLER_ID,
            OutboundScript::MSG_NO_TRUNK,
            InboundRouter::MSG_UNAVAILABLE,
            InboundRouter::MSG_INVALID,
            InboundRouter::MSG_VOICEMAIL_THANKS,
            InboundRouter::DEFAULT_DISCLOSURE,
            $recording->disclosure(),
            ...Mailbox::query()->pluck('greeting')->all(),
            ...IvrMenu::query()->pluck('greeting')->all(),
        ])));
    }
}
