<?php

namespace Tests\Unit;

use App\Domain\Calls\CallScript;
use App\Domain\Calls\DialTarget;
use App\Voice\FreeSwitch\HttapiRenderer;
use App\Voice\Prompts;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class HttapiRendererTest extends TestCase
{
    private function render(CallScript $script): string
    {
        $prompts = new Prompts(['driver' => 'freeswitch', 'engine' => 'flite', 'voice' => 'slt', 'command' => []]);

        return (new HttapiRenderer('voice.test', $prompts))->render($script);
    }

    public function test_script_without_an_ending_hangs_up(): void
    {
        $xml = $this->render((new CallScript())->say('Hello & welcome'));

        $this->assertStringContainsString('<document type="text/freeswitch-httapi"><work><speak engine="flite" voice="slt">Hello &amp; welcome</speak><hangup/></work></document>', $xml);
    }

    public function test_rendering_stops_at_the_step_that_moves_the_call_on(): void
    {
        $xml = $this->render((new CallScript())
            ->gather('Press 1.', ['action' => 'https://app.test/next', 'timeout' => 4])
            ->say('Never played'));

        $this->assertStringNotContainsString('Never played', $xml);
        $this->assertStringNotContainsString('<hangup/>', $xml);
        $this->assertStringContainsString('input-timeout="4000" action="https://app.test/next"', $xml);
    }

    public function test_colleagues_ring_together_and_trunk_addresses_are_tried_in_turn(): void
    {
        $xml = $this->render((new CallScript())->dial([
            DialTarget::client('user-4'),
            DialTarget::sip('sip:u4-abc123@voice.test'),
        ], ['timeout' => 20, 'action' => 'https://app.test/after']));
        $this->assertStringContainsString('data="{originate_timeout=20}[execute_on_answer_qd=\'bridge_export nolocal:qd_by=user-4\']user/user-4@voice.test,[execute_on_answer_qd=\'bridge_export nolocal:qd_by=u4-abc123\']user/u4-abc123@voice.test" action="https://app.test/after"', $xml);
        $this->assertStringContainsString('<getVariable name="originate_disposition"/>', $xml);

        $xml = $this->render((new CallScript())->dial([DialTarget::number('+447700900123', 'qd-t1-1|qd-t1-2')]));
        $this->assertStringContainsString('}sofia/gateway/qd-t1-1/+447700900123|sofia/gateway/qd-t1-2/+447700900123"', $xml);
        $this->assertStringContainsString('<hangup/>', $xml);
    }

    public function test_whisper_is_played_to_the_person_answering(): void
    {
        $xml = $this->render((new CallScript())->dial([DialTarget::number('+447700900123', 'qd-t1-1', 'This call, may be recorded.')]));

        $this->assertStringContainsString('[group_confirm_key=exec,group_confirm_file=speak flite|slt|This call  may be recorded.]sofia/gateway/qd-t1-1/', $xml);
    }

    public function test_recorded_calls_upload_when_they_end(): void
    {
        $xml = $this->render((new CallScript())->dial([DialTarget::client('user-1')], [
            'record' => true, 'recordingStatusCallback' => 'https://app.test/rec',
        ]));

        $this->assertStringContainsString('<recordCall name="recording" limit="14400" action="https://app.test/rec"/>', $xml);
    }

    public function test_values_that_could_inject_into_a_dial_string_are_refused(): void
    {
        foreach ([
            DialTarget::client('user-1,sofia/gateway/x/1'),
            DialTarget::number('${evil}'),
            DialTarget::number('+447700900123', 'gw}x'),
            DialTarget::sip('sip:a,b@voice.test'),
        ] as $target) {
            try {
                $this->render((new CallScript())->dial([$target]));
                $this->fail('Rendered an unsafe dial target.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_recordings_play_instead_of_spoken_text(): void
    {
        $xml = $this->render((new CallScript())->play('https://app.test/voice/sounds/abc.wav')->hangup());
        $this->assertStringContainsString('<work><playback file="https://app.test/voice/sounds/abc.wav"/><hangup/></work>', $xml);

        $xml = $this->render((new CallScript())->gather('Press 1.', ['action' => 'https://app.test/next', 'sound' => 'https://app.test/voice/sounds/menu.wav']));
        $this->assertStringContainsString('<playback file="https://app.test/voice/sounds/menu.wav" name="digits"', $xml);
        $this->assertStringNotContainsString('Press 1.', $xml);
    }

    public function test_only_web_addresses_can_be_played(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->render((new CallScript())->play('/etc/passwd'));
    }
}
