<?php

namespace App\Voice;

use App\Domain\Calls\CallScript;
use App\Http\Controllers\Admin\SoundController;
use App\Models\Mailbox;
use App\Models\Recording;
use App\Models\User;
use App\Support\RecordingStore;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * *00 from a softphone or desk phone: the voicemail boxes the caller is a
 * member of. They can play, replay and delete the messages, and record a box's
 * greeting if it is theirs (they are its only member) or they are an admin.
 *
 * Each key press comes back to VoicemailMenuController, which checks the call
 * is still this person's *00 call before anything here runs.
 */
class VoicemailMenu
{
    public const MSG_NO_BOX = "You don't have a voicemail box yet. An admin can set one up under Voicemail boxes.";
    public const MSG_GOODBYE = 'Goodbye.';
    public const MSG_GREETING_SAVED = 'Saved. Callers will now hear this greeting.';
    public const MSG_GREETING_NOT_SAVED = 'Sorry, nothing was recorded. Hang up and try again.';

    private const WAIT = 8; // seconds to press a key

    public function __construct(private readonly RecordingStore $store)
    {
    }

    /** @return Collection<int, Mailbox> */
    public function boxes(User $user): Collection
    {
        return Mailbox::query()->whereHas('members', fn ($q) => $q->whereKey($user->id))->orderBy('name')->limit(9)->get();
    }

    public function start(User $user): CallScript
    {
        $boxes = $this->boxes($user);

        return match ($boxes->count()) {
            0 => (new CallScript())->say(self::MSG_NO_BOX)->hangup(),
            1 => $this->box($user, $boxes->first()),
            default => (new CallScript())->gather(
                $boxes->values()->map(fn (Mailbox $b, int $i) => 'For '.self::spoken($b->name).', press '.($i + 1).'.')->implode(' '),
                $this->keys(['step' => 'choose']),
            ),
        };
    }

    /** The key pressed at the list of boxes. */
    public function choose(User $user, ?string $digits): CallScript
    {
        $box = $digits !== null && ctype_digit($digits) ? $this->boxes($user)->values()->get((int) $digits - 1) : null;

        return $box ? $this->box($user, $box) : $this->goodbye();
    }

    /** "Sales. You have 2 messages. To listen, press 1. To record the greeting, press 2." */
    public function box(User $user, Mailbox $box): CallScript
    {
        $count = $this->messages($box)->count();
        $canRecord = self::mayRecordGreeting($user, $box);

        $prompt = self::spoken($box->name).'. '.match ($count) {
            0 => 'You have no messages.',
            1 => 'You have 1 message.',
            default => "You have {$count} messages.",
        };
        if ($count === 0 && ! $canRecord) {
            return (new CallScript())->say($prompt)->hangup();
        }

        $prompt .= ($count > 0 ? ' To listen, press 1.' : '').($canRecord ? ' To record the greeting, press 2.' : '');

        return (new CallScript())->gather($prompt, $this->keys(['step' => 'box', 'box' => $box->id]));
    }

    /** The key pressed at a box's menu. */
    public function boxChoice(User $user, Mailbox $box, ?string $digits): CallScript
    {
        return match (true) {
            $digits === '1' => $this->play($box, $this->messages($box)->first()),
            $digits === '2' && self::mayRecordGreeting($user, $box) => (new CallScript())
                ->say('Record the greeting for '.self::spoken($box->name).' after the tone. Press hash when you have finished.')
                ->record([
                    'maxLength' => SoundController::MAX_SECONDS,
                    'action' => VoiceUrl::to('webhooks.voice.greeting-recorded', ['box' => $box->id]),
                ]),
            default => $this->goodbye(),
        };
    }

    /** The key pressed after a message: 1 replays it, 2 moves on, 7 deletes it. */
    public function messageChoice(Mailbox $box, Recording $message, ?string $digits): CallScript
    {
        $next = $this->messages($box)->first(fn (Recording $r) => $r->id > $message->id);

        switch ($digits) {
            case '1':
                return $this->play($box, $message);
            case '2':
                return $this->play($box, $next);
            case '7':
                $this->store->deleteAudio($message);
                $message->delete();

                return (new CallScript())->say('Deleted.')->append($this->play($box, $next));
            default:
                return $this->goodbye();
        }
    }

    public function greetingRecorded(?string $soundUrl): CallScript
    {
        return $soundUrl === null
            ? (new CallScript())->say(self::MSG_GREETING_NOT_SAVED)->hangup()
            : (new CallScript())->say(self::MSG_GREETING_SAVED)->play($soundUrl)->hangup();
    }

    /** Messages with audio still stored, oldest first. A box that deletes after emailing has none. */
    public function messages(Mailbox $box): Collection
    {
        return Recording::query()->where('kind', Recording::VOICEMAIL)->where('mailbox_id', $box->id)
            ->where('status', 'stored')->whereNotNull('path')->orderBy('id')->get();
    }

    /** People record their own box's greeting; a shared box's is an admin's call. */
    public static function mayRecordGreeting(User $user, Mailbox $box): bool
    {
        return $user->isAdmin() || $box->members()->pluck('users.id')->all() === [$user->id];
    }

    private function play(Mailbox $box, ?Recording $message): CallScript
    {
        if ($message === null) {
            return (new CallScript())->say('There are no more messages.')->append($this->goodbye());
        }

        $all = $this->messages($box);
        $n = $all->search(fn (Recording $r) => $r->id === $message->id) + 1;

        return (new CallScript())
            ->say("Message {$n} of {$all->count()}, from ".$this->caller($message).', '.self::when($message->created_at).'.')
            ->play(VoicemailAudio::url($message))
            ->gather('To hear it again, press 1. For the next message, press 2. To delete it, press 7.',
                $this->keys(['step' => 'message', 'box' => $box->id, 'message' => $message->id]));
    }

    private function goodbye(): CallScript
    {
        return (new CallScript())->say(self::MSG_GOODBYE)->hangup();
    }

    /** @param array<string, mixed> $query */
    private function keys(array $query): array
    {
        return ['numDigits' => 1, 'timeout' => self::WAIT, 'action' => VoiceUrl::to('webhooks.voice.voicemail-menu', $query)];
    }

    /** A colleague by name; a number digit by digit, as people say them (0 7 7 0 0…). */
    private function caller(Recording $message): string
    {
        $from = (string) $message->from;
        if (preg_match('/^user-(\d+)$/', $from, $m) && ($name = User::query()->whereKey((int) $m[1])->value('name'))) {
            return self::spoken($name);
        }

        $digits = str_starts_with($from, '+44') ? '0'.substr($from, 3) : ltrim($from, '+');

        return ctype_digit($digits) && $digits !== '' ? implode(' ', str_split($digits)) : 'a withheld number';
    }

    /** "today at 10:42", "yesterday at 16:05", "on Monday 3 November at 09:15" */
    private static function when(?Carbon $at): string
    {
        $at = ($at ?? now())->copy()->timezone('Europe/London'); // as people in the UK say it

        return match (true) {
            $at->isToday() => 'today at '.$at->format('H:i'),
            $at->isYesterday() => 'yesterday at '.$at->format('H:i'),
            default => 'on '.$at->format('l j F').' at '.$at->format('H:i'),
        };
    }

    /** Names read aloud: nothing the speech engine could trip on. */
    private static function spoken(string $name): string
    {
        return trim(preg_replace(['/[^\pL\pN \'-]+/u', '/\s+/'], ' ', $name) ?? '') ?: 'your mailbox';
    }
}
