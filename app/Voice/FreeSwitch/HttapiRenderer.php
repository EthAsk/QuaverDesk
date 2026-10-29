<?php

namespace App\Voice\FreeSwitch;

use App\Domain\Calls\CallScript;
use App\Domain\Calls\DialTarget;
use App\Domain\Calls\FollowMe;
use App\Voice\Prompts;
use DOMDocument;
use DOMElement;
use InvalidArgumentException;

/**
 * Renders a CallScript as a FreeSWITCH mod_httapi document. Built on
 * DOMDocument so every value is escaped correctly.
 *
 * How httapi moves on: each tag's "action" becomes the URL of the next
 * request once the work block ends. So a block must finish with a tag that
 * leaves the call (hangup) or sets where to go next (gather, record, dial with
 * an action, redirect); anything after such a tag would change that URL, so
 * rendering stops there. A script that ends without one gets a hangup.
 *
 * Dial strings are built only from values this app validated (E.164 numbers,
 * user-N identities, gateway names), and nothing may contain "$", so callers
 * can never inject FreeSWITCH variables or extra call legs.
 */
final class HttapiRenderer
{
    public const RECORDING_FIELD = 'recording';
    private const MAX_CALL_RECORDING_SECONDS = 4 * 3600;
    private const BEEP = 'tone_stream://%(500,0,1000)';

    public function __construct(
        private readonly string $domain,
        private readonly Prompts $prompts,
    ) {
    }

    public function render(CallScript $script): string
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $root = $doc->appendChild($doc->createElement('document'));
        $root->setAttribute('type', 'text/freeswitch-httapi');
        $work = $root->appendChild($doc->createElement('work'));

        $finished = false;
        foreach ($script->steps() as $step) {
            $finished = match ($step[0]) {
                'say' => $this->say($doc, $work, $step[1]),
                'play' => $this->play($doc, $work, $step[1]),
                'dial' => $this->dial($doc, $work, $step[1], $step[2]),
                'gather' => $this->gather($doc, $work, $step[1], $step[2]),
                'record' => $this->record($doc, $work, $step[1]),
                'park' => $this->park($doc, $work, $step[1], $step[2], $step[3]),
                'redirect' => (bool) $work->appendChild($this->el($doc, 'continue', null, ['action' => $step[1]])),
                'hangup' => (bool) $work->appendChild($doc->createElement('hangup')),
                default => throw new InvalidArgumentException("Unknown call step: {$step[0]}"),
            };
            if ($finished) {
                break;
            }
        }

        if (! $finished) {
            $work->appendChild($doc->createElement('hangup'));
        }

        return $doc->saveXML();
    }

    private function say(DOMDocument $doc, DOMElement $work, string $text): bool
    {
        $work->appendChild($this->prompt($doc, $text));

        return false;
    }

    private function play(DOMDocument $doc, DOMElement $work, string $url): bool
    {
        $work->appendChild($this->el($doc, 'playback', null, ['file' => self::url($url)]));

        return false;
    }

    /** @param list<DialTarget> $targets */
    private function dial(DOMDocument $doc, DOMElement $work, array $targets, array $o): bool
    {
        if (! empty($o['record']) && ! empty($o['recordingStatusCallback'])) {
            // Records the rest of the call; FreeSWITCH uploads the file to the action when the call ends.
            $work->appendChild($this->el($doc, 'recordCall', null, [
                'name' => self::RECORDING_FIELD,
                'limit' => self::MAX_CALL_RECORDING_SECONDS,
                'action' => $o['recordingStatusCallback'],
            ]));
        }

        $vars = ['originate_timeout' => (int) ($o['timeout'] ?? 30)];
        if (! empty($o['maxSeconds'])) {
            // The voice server ends the call itself, so a limit holds even if this app is unreachable.
            $vars['execute_on_answer'] = "'sched_hangup +".max(1, (int) $o['maxSeconds'])." ALLOTTED_TIMEOUT'";
        }
        if (! empty($o['callerId'])) {
            $vars['origination_caller_id_number'] = self::safe($o['callerId'], '/^\+?[0-9]{3,15}$/');
            $vars['origination_caller_id_name'] = $vars['origination_caller_id_number'];
        }

        $legs = array_map(fn (DialTarget $t) => $this->leg($t), $targets);

        $work->appendChild($this->el($doc, 'execute', null, [
            'application' => 'bridge',
            'data' => '{'.self::vars($vars).'}'.implode(',', $legs),
            'action' => $o['action'] ?? null,
        ]));

        if (isset($o['action'])) {
            // Sends the dial outcome (originate_disposition) with the request to the action,
            // and which leg answered (last_bridge_to) when one of them is logged on its own.
            $work->appendChild($this->el($doc, 'getVariable', null, ['name' => 'originate_disposition']));
            if (array_filter($targets, fn (DialTarget $t) => $t->legId !== null)) {
                $work->appendChild($this->el($doc, 'getVariable', null, ['name' => 'last_bridge_to']));
            }
        } else {
            $work->appendChild($doc->createElement('hangup'));
        }

        return true;
    }

    private function leg(DialTarget $t): string
    {
        return match ($t->kind) {
            'client' => self::answeredBy(self::safe($t->value, '/^user-\d{1,9}$/')).'user/'.$t->value.'@'.$this->domain,
            'sip' => self::answeredBy(self::sipUser($t->value)).'user/'.self::sipUser($t->value).'@'.$this->domain,
            default => $this->numberLeg($t),
        };
    }

    /**
     * When one of our phones answers, the other side of the call learns which
     * phone it is talking to (qd_by), so a transfer that phone makes is checked
     * and counted against the right person.
     */
    private static function answeredBy(string $phone): string
    {
        return '['.self::vars(['execute_on_answer_qd' => "'bridge_export nolocal:qd_by={$phone}'"]).']';
    }

    private function numberLeg(DialTarget $t): string
    {
        $number = self::safe($t->value, '/^\+?[0-9]{3,15}$/');
        $gateways = array_map(
            fn (string $g) => self::safe($g, '/^[a-z0-9-]{1,40}$/'),
            explode('|', (string) $t->trunk),
        );

        $prefix = '';
        if ($t->followMe !== null) {
            $prefix = $this->followMeVars($t);
        } elseif ($t->whisper !== null) {
            // Played to the called person after they answer, before the caller is connected.
            [$kind, $value] = $this->prompts->for($t->whisper);
            $app = $kind === 'file'
                ? 'playback '.self::safe($value, '#^https?://[A-Za-z0-9.:/_?=&%-]+$#')
                : 'speak '.$this->prompts->engine().'|'.$this->prompts->voice().'|'.self::plainText($value);
            $prefix = '['.self::vars(['group_confirm_key' => 'exec', 'group_confirm_file' => $app]).']';
        }

        // Several gateways (one per provider address) are tried in order.
        return implode('|', array_map(fn (string $g) => $prefix.'sofia/gateway/'.$g.'/'.$number, $gateways));
    }

    /** A person's mobile: their company's number shown, an optional delay, and "press 1" so its voicemail can't take the call. */
    private function followMeVars(DialTarget $t): string
    {
        $f = $t->followMe;
        $callerId = self::safe($f->callerId, '/^\+?[0-9]{3,15}$/');
        [$kind, $value] = $this->prompts->for(FollowMe::SCREEN);

        $vars = array_filter([
            'origination_uuid' => $t->legId !== null ? self::safe($t->legId, '/^[0-9a-f-]{36}$/') : null,
            'origination_caller_id_number' => $callerId,
            'origination_caller_id_name' => $callerId,
            'leg_delay_start' => $f->delaySeconds > 0 ? $f->delaySeconds : null,
            'group_confirm_key' => '1',
            'group_confirm_file' => $kind === 'file' ? self::safe($value, '#^https?://[A-Za-z0-9.:/_?=&%-]+$#') : 'say:'.self::plainText($value),
            'group_confirm_read_timeout' => 8000,
            'tts_engine' => $kind === 'file' ? null : $this->prompts->engine(),
            'tts_voice' => $kind === 'file' ? null : $this->prompts->voice(),
            'execute_on_answer_qd' => "'sched_hangup +".max(1, $f->maxSeconds)." ALLOTTED_TIMEOUT'",
        ], fn ($v) => $v !== null);

        return '['.self::vars($vars).']';
    }

    /**
     * Parks the call on a slot of the company's lot, or picks up the call
     * already parked there. A parked call nobody picks up goes back to
     * whoever parked it, through the transfer webhook.
     */
    private function park(DOMDocument $doc, DOMElement $work, string $lot, int $slot, array $o): bool
    {
        $vars = array_filter([
            'valet_parking_timeout' => (int) ($o['waitSeconds'] ?? 180),
            'valet_hold_music' => isset($o['holdMusic']) ? self::safe($o['holdMusic'], '#^https?://[A-Za-z0-9.:/_?=&%-]+$#') : null,
            'valet_parking_orbit_exten' => isset($o['returnTo']) ? self::safe($o['returnTo'], '/^(user-\d{1,9}|u\d{1,9}-[a-f0-9]{6})$/') : null,
            'valet_parking_orbit_context' => isset($o['returnTo']) ? 'qd-transfer' : null,
            'valet_parking_orbit_dialplan' => isset($o['returnTo']) ? 'XML' : null,
        ], fn ($v) => $v !== null);
        foreach ($vars as $name => $value) {
            $work->appendChild($this->el($doc, 'execute', null, ['application' => 'set', 'data' => "{$name}={$value}"]));
        }

        $data = self::safe($lot, '/^qd-park-\d{1,9}$/').' '.self::safe((string) $slot, '/^[1-9]$/');
        $work->appendChild($this->el($doc, 'execute', null, ['application' => 'valet_park', 'data' => $data]));
        $work->appendChild($doc->createElement('hangup'));

        return true;
    }

    private function gather(DOMDocument $doc, DOMElement $work, string $prompt, array $o): bool
    {
        $tag = empty($o['sound'])
            ? $this->prompt($doc, $prompt)
            : $this->el($doc, 'playback', null, ['file' => self::url($o['sound'])]);
        $tag->setAttribute('name', 'digits');
        $tag->setAttribute('input-timeout', (string) (1000 * (int) ($o['timeout'] ?? 5)));
        $tag->setAttribute('action', $o['action']);
        $tag->appendChild($this->el($doc, 'bind', '~\\d{'.max(1, (int) ($o['numDigits'] ?? 1)).'}', []));
        $work->appendChild($tag);

        return true;
    }

    private function record(DOMDocument $doc, DOMElement $work, array $o): bool
    {
        $work->appendChild($this->el($doc, 'record', null, [
            'file' => 'voicemail.wav',
            'name' => self::RECORDING_FIELD,
            'limit' => (int) ($o['maxLength'] ?? 120),
            'silence-hits' => 5,
            'beep-file' => self::BEEP,
            'action' => $o['action'],
        ]));

        return true;
    }

    private function prompt(DOMDocument $doc, string $text): DOMElement
    {
        [$kind, $value] = $this->prompts->for($text);

        return $kind === 'file'
            ? $this->el($doc, 'playback', null, ['file' => $value])
            : $this->el($doc, 'speak', $value, ['engine' => $this->prompts->engine(), 'voice' => $this->prompts->voice()]);
    }

    /** @param array<string, scalar|null> $attrs null values are omitted */
    private function el(DOMDocument $doc, string $name, ?string $text, array $attrs = []): DOMElement
    {
        $el = $doc->createElement($name);
        if ($text !== null) {
            $el->appendChild($doc->createTextNode($text));
        }
        foreach ($attrs as $k => $v) {
            if ($v !== null) {
                $el->setAttribute($k, (string) $v);
            }
        }

        return $el;
    }

    /** @param array<string, scalar> $vars */
    private static function vars(array $vars): string
    {
        return implode(',', array_map(fn ($k, $v) => $k.'='.$v, array_keys($vars), $vars));
    }

    private static function sipUser(string $uri): string
    {
        if (! preg_match('/^sips?:([A-Za-z0-9_-]{1,64})@/i', $uri, $m)) {
            throw new InvalidArgumentException('Not a desk phone address.');
        }

        return $m[1];
    }

    private static function safe(string $value, string $pattern): string
    {
        if (! preg_match($pattern, $value) || str_contains($value, '$')) {
            throw new InvalidArgumentException('Refusing an unsafe value in a dial string.');
        }

        return $value;
    }

    private static function url(string $url): string
    {
        if (! preg_match('#^https?://#', $url)) {
            throw new InvalidArgumentException('Audio must be fetched over http(s).');
        }

        return $url;
    }

    /** Text for an inline speak command: no characters that mean something in a dial string. */
    private static function plainText(string $text): string
    {
        return trim(preg_replace('/[^A-Za-z0-9 .\'?!-]+/', ' ', $text) ?? '');
    }
}
