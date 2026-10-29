<?php

namespace Tests\Feature;

use App\Domain\Licensing\Plan;
use App\Domain\Telephony\DialDecision;
use App\Domain\Telephony\Dialplan;
use App\Http\Controllers\Webhooks\VoiceController;
use App\Models\Call;
use App\Models\RingGroup;
use App\Models\Setting;
use App\Models\SipTrunk;
use App\Models\User;
use App\Voice\FreeSwitch\FreeSwitchServer;
use App\Voice\VoiceServer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Transfer, hold and park. */
class TransferAndParkTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $sam;
    private FreeSwitchServer $voice;
    private string $lot;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->usePlan(Plan::Plus);
        $this->owner = User::factory()->owner()->create();
        $this->sam = User::factory()->create();
        $this->sam->forceFill(['extension' => '230'])->save();
        $this->acknowledgeTerms($this->owner);
        config(['voip.caller_id' => '+442079460000']);
        SipTrunk::create([
            'name' => 'HQ', 'provider' => 'other', 'inbound_ips' => [['ip' => '203.0.113.10', 'prefix' => 32]],
            'origination_uris' => ['sip:sbc.example.net'], 'status' => 'active',
        ])->forceFill(['default_outbound' => true])->save();

        // The voice server, with its parking lots in memory.
        $this->voice = new class(config('voip.freeswitch')) extends FreeSwitchServer
        {
            public array $parked = [];

            public function parkedSlots(string $lot): array
            {
                return $this->parked[$lot] ?? [];
            }
        };
        $this->app->instance(VoiceServer::class, $this->voice);
        $this->lot = 'qd-park-'.$this->owner->tenant_id;

        // A call from outside, answered by the owner.
        Call::create(['carrier_sid' => self::callId(1), 'direction' => 'inbound', 'from' => '+447700900123', 'to' => '+442079460000',
            'decision' => 'user', 'status' => 'completed']);
    }

    /** The owner's phone transfers the call to $to. */
    private function transfer(string $to, ?string $by = null, int $leg = 1): string
    {
        return $this->voicePost('/webhooks/voice/transfer', [
            'session_id' => self::callId($leg), 'call' => self::callId(1), 'by' => $by ?? $this->owner->carrierIdentity(),
            'Caller-Destination-Number' => $to,
        ])->assertOk()->getContent();
    }

    public function test_parking_codes(): void
    {
        $plan = new Dialplan();
        $this->assertEquals(DialDecision::park(null), $plan->decide('**'));
        $this->assertEquals(DialDecision::park(3), $plan->decide('**3'));
        $this->assertSame(DialDecision::REJECT, $plan->decide('**0')->type);
        $this->assertSame(DialDecision::CLIENT, $plan->decide('*3')->type); // still a colleague's shortcut
    }

    public function test_a_call_transferred_to_an_extension_rings_that_person_then_their_voicemail(): void
    {
        $xml = $this->transfer('230');

        $this->assertStringContainsString("user/user-{$this->sam->id}@voice.test", $xml);
        $leg = Call::query()->where('leg', Call::LEG_TRANSFER)->sole();
        $this->assertSame([self::callId(1), $this->owner->id, 'internal', 'user-'.$this->sam->id, 'initiated'],
            [$leg->parent_sid, $leg->user_id, $leg->direction, $leg->to, $leg->status]);

        // The transfer's outcome is its own; the call as it arrived keeps its own.
        $this->voicePost('/webhooks/voice/after-dial?next=hangup', ['session_id' => self::callId(1), 'originate_disposition' => 'NO_ANSWER']);
        $this->assertSame('no-answer', $leg->fresh()->status);
        $this->assertSame('completed', Call::firstWhere('carrier_sid', self::callId(1))->status);

        $this->voicePost('/webhooks/voice/after-dial', ['session_id' => self::callId(1), 'exiting' => 'true'])->assertNoContent();
        $this->assertNotNull($leg->fresh()->duration_seconds);
    }

    public function test_a_call_transferred_outside_uses_the_company_number_and_the_transferrers_limits(): void
    {
        Setting::put('calling.limits', ['calls_per_day' => 1, 'max_call_minutes' => 5]);

        $xml = $this->transfer('07700900456', leg: 2);
        $this->assertStringContainsString("execute_on_answer='sched_hangup +300 ALLOTTED_TIMEOUT',origination_caller_id_number=+442079460000", $xml);
        $this->assertStringContainsString('/+447700900456', $xml);
        $leg = Call::query()->where('parent_sid', self::callId(2))->sole();
        $this->assertSame(['outbound', DialDecision::NUMBER, $this->owner->id], [$leg->direction, $leg->decision, $leg->user_id]);

        // Over today's limit: it comes back to the person who transferred it, rather than being dropped.
        $xml = $this->transfer('07700900789', leg: 3);
        $this->assertStringNotContainsString('+447700900789', $xml);
        $this->assertStringContainsString("user/user-{$this->owner->id}@voice.test", $xml);
    }

    public function test_a_call_can_be_transferred_to_a_ring_group_by_its_extension(): void
    {
        $group = RingGroup::create(['name' => 'Sales', 'extension' => '500', 'strategy' => 'simultaneous', 'ring_seconds' => 20]);
        $group->members()->attach($this->sam->id, ['position' => 0]);

        $this->assertStringContainsString("{originate_timeout=20}[execute_on_answer_qd='bridge_export nolocal:qd_by=user-{$this->sam->id}']", $this->transfer('500'));

        // Phones can dial it too.
        $xml = $this->voicePost('/webhooks/voice/outgoing', ['session_id' => self::callId(9), 'from' => $this->owner->carrierIdentity(),
            'Caller-Destination-Number' => '500'])->getContent();
        $this->assertStringContainsString("user/user-{$this->sam->id}@voice.test", $xml);
        $this->assertSame(['internal', DialDecision::RING_GROUP], [Call::firstWhere('carrier_sid', self::callId(9))->direction, Call::firstWhere('carrier_sid', self::callId(9))->decision]);
    }

    public function test_ring_group_extensions_never_clash_with_peoples(): void
    {
        $this->owner->forceFill(['two_factor_confirmed_at' => now(), 'two_factor_secret' => encrypt('x')])->save();
        $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => time()]);

        $this->get('/admin/ring-groups/new')->assertOk()->assertSee('name="extension"', false);
        $this->post('/admin/ring-groups', ['name' => 'Sales', 'extension' => '230', 'strategy' => 'simultaneous', 'ring_seconds' => 20])
            ->assertSessionHasErrors(['extension' => 'Someone already has that extension.']);
        $this->post('/admin/ring-groups', ['name' => 'Sales', 'extension' => '500', 'strategy' => 'simultaneous', 'ring_seconds' => 20])
            ->assertSessionHasNoErrors();
        $this->patch("/admin/users/{$this->sam->id}/extension", ['extension' => '500'])
            ->assertSessionHasErrors(['extension' => 'A ring group already has that extension.'], null, 'extension');

        // New people are never given a ring group's extension.
        RingGroup::create(['name' => 'Support', 'extension' => User::nextExtension(), 'strategy' => 'simultaneous', 'ring_seconds' => 20]);
        $this->assertNotContains(User::create(['name' => 'New', 'email' => 'new@example.test', 'password' => 'x'])->extension,
            RingGroup::query()->pluck('extension')->all());
    }

    public function test_parking_puts_the_call_in_a_free_space_and_brings_it_back_unanswered(): void
    {
        $this->voice->parked[$this->lot] = [1];

        $xml = $this->transfer('**');
        $this->assertStringContainsString('<execute application="set" data="valet_parking_timeout=180"/>', $xml);
        $this->assertStringContainsString("<execute application=\"set\" data=\"valet_parking_orbit_exten={$this->owner->carrierIdentity()}\"/>", $xml);
        $this->assertStringContainsString('data="valet_parking_orbit_context=qd-transfer"', $xml);
        $this->assertStringContainsString("<execute application=\"valet_park\" data=\"{$this->lot} 2\"/>", $xml);
        $this->assertSame('**2', Call::query()->where('leg', Call::LEG_TRANSFER)->value('to'));

        // A space that's taken isn't used; another is.
        $this->assertStringContainsString("data=\"{$this->lot} 3\"", $this->transfer('**1', leg: 2));
        $this->assertStringContainsString("data=\"{$this->lot} 5\"", $this->transfer('**5', leg: 3));

        // Nobody picked up: the voice server sends it back to the person who parked it.
        $this->assertStringContainsString("user/user-{$this->owner->id}@voice.test", $this->transfer($this->owner->carrierIdentity()));
    }

    public function test_any_phone_picks_up_a_parked_call_by_dialling_its_space(): void
    {
        $this->voice->parked[$this->lot] = [4];
        $dial = fn (string $to, int $n) => $this->voicePost('/webhooks/voice/outgoing', [
            'session_id' => self::callId($n), 'from' => $this->sam->carrierIdentity(), 'Caller-Destination-Number' => $to,
        ])->getContent();

        $this->assertStringContainsString("<execute application=\"valet_park\" data=\"{$this->lot} 4\"/>", $dial('**4', 20));
        $this->assertStringContainsString('There is no call parked on 5.', $dial('**5', 21));
        $this->assertStringContainsString(VoiceController::MSG_PICK_UP_HOW, $dial('**', 22));
    }

    public function test_the_phone_is_given_a_free_space_to_park_on(): void
    {
        $this->voice->parked[$this->lot] = [1, 2];
        $this->actingAs($this->sam);

        $this->postJson('/softphone/park')->assertOk()->assertExactJson(['slot' => 3]);
        $this->postJson('/softphone/park')->assertOk()->assertExactJson(['slot' => 4]); // 3 is held for the first phone

        $this->voice->parked[$this->lot] = range(1, 9);
        $this->postJson('/softphone/park')->assertStatus(409);
    }

    public function test_an_emergency_call_is_never_transferred_or_parked_away(): void
    {
        Call::create(['carrier_sid' => self::callId(99), 'user_id' => $this->sam->id, 'direction' => 'outbound', 'from' => 'user-'.$this->sam->id,
            'to' => '999', 'decision' => DialDecision::EMERGENCY, 'status' => 'initiated']);

        foreach (['07700900456', '**', '230'] as $i => $to) {
            $xml = $this->voicePost('/webhooks/voice/transfer', [
                'session_id' => self::callId(100 + $i), 'call' => self::callId(99), 'by' => $this->sam->carrierIdentity(), 'Caller-Destination-Number' => $to,
            ])->getContent();

            $this->assertStringContainsString("user/user-{$this->sam->id}@voice.test", $xml);
            $this->assertStringNotContainsString('valet_park', $xml);
            $this->assertStringNotContainsString('sofia/gateway', $xml);
        }
    }

    public function test_a_transfer_from_an_unknown_phone_is_refused(): void
    {
        $this->assertStringContainsString(VoiceController::MSG_CANT_TRANSFER, $this->transfer('230', by: ''));
        $this->assertStringContainsString(VoiceController::MSG_CANT_TRANSFER, $this->transfer('230', by: 'user-999999'));
    }
}
