<?php

namespace App\Domain\Calls;

/**
 * Voice-server-neutral description of what should happen on a call. Routing
 * code builds a CallScript; the voice server driver renders it.
 *
 * Each step is a plain array so the format is easy to read, test and render:
 *   ['say', text]
 *   ['play', url]                         an audio file (an uploaded greeting)
 *   ['dial', list<DialTarget>, options]   options: timeout, callerId, action (gets the dial outcome),
 *                                                  maxSeconds (hang up once answered this long),
 *                                                  record (bool), recordingStatusCallback (gets the audio)
 *   ['gather', prompt, options]           options: numDigits, timeout, action, sound (a URL played
 *                                         instead of speaking the prompt). Ends the script: the
 *                                         caller's key press (or nothing, on timeout) goes to action
 *   ['record', options]                   options: maxLength, action. Ends the script: the
 *                                         recording (if any) goes to action
 *   ['park', lot, slot, options]          park the call on a slot, or pick up the call parked there.
 *                                         options (parking only): holdMusic, returnTo (dialled again,
 *                                         in the transfer context, if nobody picks up), waitSeconds
 *   ['redirect', url]
 *   ['hangup']
 */
final class CallScript
{
    /** @var list<array> */
    private array $steps = [];

    public function say(string $text): self
    {
        $this->steps[] = ['say', $text];

        return $this;
    }

    public function play(string $url): self
    {
        $this->steps[] = ['play', $url];

        return $this;
    }

    /** A recording if there is one, otherwise the text read aloud. */
    public function sayOrPlay(string $text, ?string $soundUrl): self
    {
        return $soundUrl !== null ? $this->play($soundUrl) : $this->say($text);
    }

    /**
     * @param  list<DialTarget>  $targets  dialled together; the first to answer wins
     * @param  array<string, mixed>  $options
     */
    public function dial(array $targets, array $options = []): self
    {
        $this->steps[] = ['dial', $targets, $options];

        return $this;
    }

    /** @param array{numDigits?: int, timeout?: int, action: string, sound?: ?string} $options */
    public function gather(string $prompt, array $options): self
    {
        $this->steps[] = ['gather', $prompt, $options];

        return $this;
    }

    /** @param array{maxLength?: int, action: string} $options */
    public function record(array $options): self
    {
        $this->steps[] = ['record', $options];

        return $this;
    }

    /** @param array{holdMusic?: ?string, returnTo?: ?string, waitSeconds?: int} $options */
    public function park(string $lot, int $slot, array $options = []): self
    {
        $this->steps[] = ['park', $lot, $slot, $options];

        return $this;
    }

    public function redirect(string $url): self
    {
        $this->steps[] = ['redirect', $url];

        return $this;
    }

    public function hangup(): self
    {
        $this->steps[] = ['hangup'];

        return $this;
    }

    public function append(CallScript $other): self
    {
        array_push($this->steps, ...$other->steps());

        return $this;
    }

    /** @param callable(DialTarget): DialTarget $change applied to every dial target */
    public function mapTargets(callable $change): self
    {
        foreach ($this->steps as $i => $step) {
            if ($step[0] === 'dial') {
                $this->steps[$i][1] = array_map($change, $step[1]);
            }
        }

        return $this;
    }

    /** @return list<array> */
    public function steps(): array
    {
        return $this->steps;
    }
}
