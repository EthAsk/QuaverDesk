<?php

namespace Tests\Feature;

use App\Domain\Licensing\Plan;
use App\Http\Controllers\Webhooks\VoiceController;
use App\Models\Call;
use App\Models\PhoneNumber;
use App\Models\RingGroup;
use App\Models\Setting;
use App\Models\SipTrunk;
use App\Models\User;
use App\Voice\FreeSwitch\FreeSwitchServer;
use App\Voice\VoiceServer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Ring my mobile too. */
class FollowMeTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $sam;
    private SipTrunk $trunk;
    private FreeSwitchServer $voice;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->usePlan(Plan::Plus);
        $this->owner = User::factory()->owner()->create();
        $this->sam = User::factory()->create();
        $this->acknowledgeTerms($this->owner);
        config(['voip.caller_id' => '+442079460000']);
        $this->trunk = SipTrunk::create([
            'name' => 'HQ', 'provider' => 'other', 'inbound_ips' => [['ip' => '203.0.113.10', 'prefix' => 32]],
            'origination_uris' => ['sip:sbc.example.net'], 'status' => 'active',
        ]);
        $this->trunk->forceFill(['default_outbound' => true])->save();
        PhoneNumber::create(['e164' => '+442079460000', 'destination' => "user:{$this->sam->id}", 'sip_trunk_id' => $this->trunk->id]);

        $this->voice = new class(config('voip.freeswitch')) extends FreeSwitchServer
        {
            public array $calls = [];

            public function callNumber(string $e164, string $trunk, string $callerId, string $callId, string $url): void
            {
                $this->calls[] = compact('e164', 'trunk', 'callerId', 'callId', 'url');
            }
        };
        $this->app->instance(VoiceServer::class, $this->voice);
    }

    private function confirmedMobile(string $mode = User::FOLLOW_ME_TOGETHER): void
    {
        $this->sam->forceFill(['mobile' => '+447700900123', 'mobile_verified_at' => now(), 'follow_me' => $mode, 'follow_me_delay' => 10])->save();
    }

    private function inbound(int $n): string
    {
        return $this->voicePost('/webhooks/voice/inbound', [
            'session_id' => self::callId($n), 'Caller-Caller-ID-Number' => '+447700900999', 'Caller-Destination-Number' => '+442079460000',
        ])->getContent();
    }

    public function test_a_person_sets_their_mobile_and_confirms_it_by_pressing_1(): void
    {
        $this->actingAs($this->sam);
        $this->get('/softphone')->assertOk()->assertSee('Ring my mobile too')->assertSee('Transfer and park');
        $this->get('/phone')->assertOk()->assertSee('id="xfer-ask"', false)->assertSee('data-park-url', false);

        $this->put('/softphone/mobile', ['mobile' => '020 7946 0000', 'follow_me' => 'together', 'follow_me_delay' => 10])
            ->assertSessionHasErrors('mobile');
        $this->put('/softphone/mobile', ['mobile' => '07700 900123', 'follow_me' => 'together', 'follow_me_delay' => 10])
            ->assertSessionHasNoErrors();
        $this->assertSame('+447700900123', $this->sam->fresh()->mobile);
        $this->assertFalse($this->sam->fresh()->followsMe());

        $this->post('/softphone/mobile/confirm')->assertSessionHasNoErrors();
        [$placed] = $this->voice->calls;
        $this->assertSame(['+447700900123', $this->trunk->gatewayNames()[0], '+442079460000'], [$placed['e164'], $placed['trunk'], $placed['callerId']]);
        $this->assertStringEndsWith('/webhooks/voice/confirm-mobile', $placed['url']);
        $log = Call::firstWhere('carrier_sid', $placed['callId']);
        $this->assertSame(['outbound', 'number', $this->sam->id], [$log->direction, $log->decision, $log->user_id]); // an outside call

        $ask = $this->voicePost('/webhooks/voice/confirm-mobile', ['session_id' => $placed['callId']])->getContent();
        $this->assertStringContainsString('confirm-mobile?asked=1', $ask);
        $this->voicePost('/webhooks/voice/confirm-mobile?asked=1', ['session_id' => $placed['callId'], 'digits' => '1'])
            ->assertSee('Your mobile is confirmed');
        $this->assertTrue($this->sam->fresh()->followsMe());

        // A new number has to be confirmed again.
        $this->actingAs($this->sam->fresh())->put('/softphone/mobile', ['mobile' => '07700900456', 'follow_me' => 'together', 'follow_me_delay' => 10]);
        $this->assertFalse($this->sam->fresh()->followsMe());
    }

    public function test_pressing_nothing_or_a_changed_number_confirms_nothing(): void
    {
        $this->sam->forceFill(['mobile' => '+447700900123'])->save();
        Call::create(['carrier_sid' => self::callId(5), 'user_id' => $this->sam->id, 'direction' => 'outbound', 'decision' => 'number',
            'from' => '+442079460000', 'to' => '+447700900123', 'status' => 'initiated']);

        $this->voicePost('/webhooks/voice/confirm-mobile?asked=1', ['session_id' => self::callId(5)])->assertSee('was not confirmed');
        $this->sam->forceFill(['mobile' => '+447700900456'])->save();
        $this->voicePost('/webhooks/voice/confirm-mobile?asked=1', ['session_id' => self::callId(5), 'digits' => '1']);

        $this->assertNull($this->sam->fresh()->mobile_verified_at);
    }

    public function test_calls_ring_the_mobile_too_and_it_must_press_1(): void
    {
        $this->confirmedMobile();

        $xml = $this->inbound(1);

        $leg = Call::query()->where('leg', Call::LEG_FOLLOW_ME)->sole();
        $this->assertStringContainsString("[origination_uuid={$leg->carrier_sid},origination_caller_id_number=+442079460000,origination_caller_id_name=+442079460000,group_confirm_key=1,group_confirm_file=", $xml);
        $this->assertStringContainsString("execute_on_answer_qd='sched_hangup +7200 ALLOTTED_TIMEOUT']sofia/gateway/{$this->trunk->gatewayNames()[0]}/+447700900123", $xml);
        $this->assertStringContainsString("user/user-{$this->sam->id}@voice.test", $xml);
        $this->assertStringContainsString('<getVariable name="last_bridge_to"/>', $xml);
        $this->assertSame([self::callId(1), 'outbound', 'number', $this->sam->id, 'ringing', null],
            [$leg->parent_sid, $leg->direction, $leg->decision, $leg->user_id, $leg->status, $leg->duration_seconds]);
    }

    public function test_or_only_after_a_few_seconds(): void
    {
        $this->confirmedMobile(User::FOLLOW_ME_AFTER);

        $this->assertStringContainsString('origination_caller_id_name=+442079460000,leg_delay_start=10,group_confirm_key=1', $this->inbound(1));
    }

    public function test_the_mobile_that_answered_counts_as_an_outside_call_and_one_that_did_not_counts_nothing(): void
    {
        $this->confirmedMobile();
        $this->inbound(1);
        $this->inbound(2);
        [$answered, $missed] = Call::query()->where('leg', Call::LEG_FOLLOW_ME)->orderBy('id')->get()->all();

        $this->travel(2)->minutes();
        $this->voicePost('/webhooks/voice/after-dial?next=hangup', ['session_id' => self::callId(1), 'originate_disposition' => 'SUCCESS', 'last_bridge_to' => $answered->carrier_sid]);
        $this->voicePost('/webhooks/voice/after-dial?next=hangup', ['session_id' => self::callId(2), 'exiting' => 'true'])->assertNoContent();

        $this->assertSame(['completed', 120], [$answered->fresh()->status, $answered->fresh()->duration_seconds]);
        $this->assertSame(['no-answer', 0], [$missed->fresh()->status, $missed->fresh()->duration_seconds]);
    }

    public function test_the_mobile_is_left_out_when_it_cannot_be_called(): void
    {
        // Not confirmed.
        $this->sam->forceFill(['mobile' => '+447700900123', 'follow_me' => 'together'])->save();
        $this->assertStringNotContainsString('+447700900123', $this->inbound(1));

        // Over the person's limit for outside calls today: their phones still ring.
        $this->confirmedMobile();
        Setting::put('calling.limits', ['calls_per_day' => 0]);
        $xml = $this->inbound(2);
        $this->assertStringNotContainsString('+447700900123', $xml);
        $this->assertStringContainsString("user/user-{$this->sam->id}@voice.test", $xml);
        $this->assertSame(0, Call::query()->where('leg', Call::LEG_FOLLOW_ME)->count());
    }

    public function test_ring_groups_ring_phones_only(): void
    {
        $this->confirmedMobile();
        $group = RingGroup::create(['name' => 'Sales', 'strategy' => 'simultaneous', 'ring_seconds' => 20]);
        $group->members()->attach($this->sam->id, ['position' => 0]);
        PhoneNumber::query()->update(['destination' => "ring_group:{$group->id}"]);

        $this->assertStringNotContainsString('+447700900123', $this->inbound(1));
    }

    public function test_confirming_needs_a_line_to_call_on(): void
    {
        $this->sam->forceFill(['mobile' => '+447700900123'])->save();
        SipTrunk::query()->update(['default_outbound' => false]);

        $this->actingAs($this->sam)->post('/softphone/mobile/confirm')->assertSessionHasErrors('mobile');
        $this->assertSame([], $this->voice->calls);
        $this->assertStringContainsString('Hello. This call is to confirm', VoiceController::MSG_CONFIRM_MOBILE);
    }
}
