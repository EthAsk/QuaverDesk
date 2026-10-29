<?php

namespace Tests\Feature;

use App\Domain\Licensing\Plan;
use App\Mail\CallLimitReached;
use App\Models\Call;
use App\Models\Setting;
use App\Models\SipTrunk;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CallLimitsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $caller;
    private int $n = 500;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->usePlan(Plan::Plus);
        $this->owner = User::factory()->owner()->create();
        $this->caller = User::factory()->create();
        $this->acknowledgeTerms($this->owner);
        config(['voip.caller_id' => '+442079460000']);
        $trunk = SipTrunk::create([
            'name' => 'HQ', 'provider' => 'other', 'inbound_ips' => [['ip' => '203.0.113.10', 'prefix' => 32]],
            'origination_uris' => ['sip:sbc.example.net'], 'status' => 'active',
        ]);
        $trunk->forceFill(['default_outbound' => true])->save();
        Setting::put('calling.limits', ['calls_per_day' => 3, 'minutes_per_day' => 10, 'max_call_minutes' => 5, 'concurrent' => 2]);
    }

    private function dial(string $to, ?User $from = null): string
    {
        return $this->voicePost('/webhooks/voice/outgoing', [
            'session_id' => self::callId($this->n++), 'from' => ($from ?? $this->caller)->carrierIdentity(), 'Caller-Destination-Number' => $to,
        ])->getContent();
    }

    /** An earlier outside call today that has ended. */
    private function pastCall(User $user, int $seconds): void
    {
        Call::create([
            'carrier_sid' => self::callId($this->n++), 'user_id' => $user->id, 'direction' => 'outbound', 'decision' => 'number',
            'from' => $user->carrierIdentity(), 'to' => '+447700900123', 'status' => 'ended', 'duration_seconds' => $seconds,
        ]);
    }

    public function test_every_outside_call_is_cut_off_at_the_limit(): void
    {
        $xml = $this->dial('07700900123');

        $this->assertStringContainsString("{originate_timeout=30,execute_on_answer='sched_hangup +300 ALLOTTED_TIMEOUT',", $xml);
    }

    public function test_the_last_minutes_of_the_day_shorten_the_call(): void
    {
        $this->pastCall($this->caller, 8 * 60);

        $this->assertStringContainsString("'sched_hangup +120 ALLOTTED_TIMEOUT'", $this->dial('07700900123'));
    }

    public function test_daily_calls_run_out_and_admins_hear_about_it_once(): void
    {
        foreach (range(1, 3) as $_) {
            $this->pastCall($this->caller, 10);
        }

        $xml = $this->dial('07700900123');
        $this->assertStringContainsString("reached today's limit of 3 outside calls", $xml);
        $this->assertStringNotContainsString('sofia/gateway', $xml);
        $this->dial('07700900123');

        Mail::assertQueued(CallLimitReached::class, 1);
        Mail::assertQueued(CallLimitReached::class, fn (CallLimitReached $m) => $m->hasTo($this->owner->email) && $m->caller->is($this->caller));
    }

    public function test_daily_minutes_run_out(): void
    {
        $this->pastCall($this->caller, 10 * 60);

        $this->assertStringContainsString("used today's 10 minutes", $this->dial('07700900123'));
    }

    public function test_simultaneous_outside_calls_are_capped_for_everyone(): void
    {
        $other = User::factory()->create();
        $this->dial('07700900123', $other);
        $this->dial('07700900124', $other); // both still going (no end reported)

        $this->assertStringContainsString('All 2 outside lines are in use', $this->dial('07700900125'));
    }

    public function test_emergency_and_colleague_calls_are_never_limited(): void
    {
        foreach (range(1, 5) as $_) {
            $this->pastCall($this->caller, 10 * 60);
        }

        $emergency = $this->dial('999');
        $this->assertStringContainsString('sofia/gateway/', $emergency);
        $this->assertStringNotContainsString('sched_hangup', $emergency);

        $this->assertStringContainsString('user/'.$this->owner->carrierIdentity().'@', $this->dial($this->owner->extension));
        Mail::assertNothingQueued();
    }

    public function test_admins_set_the_limits(): void
    {
        $this->actingAs(User::factory()->owner()->withTwoFactor()->create())->withSession(['auth.password_confirmed_at' => time()]);

        $this->put('/admin/calling-rules', ['limits' => ['calls_per_day' => 50, 'minutes_per_day' => 120, 'max_call_minutes' => 60, 'concurrent' => 0]])
            ->assertSessionHasErrors('limits.concurrent');
        $this->put('/admin/calling-rules', ['limits' => ['calls_per_day' => 50, 'minutes_per_day' => 120, 'max_call_minutes' => 60, 'concurrent' => 6]])
            ->assertSessionHasNoErrors();

        $this->assertSame(['calls_per_day' => 50, 'minutes_per_day' => 120, 'max_call_minutes' => 60, 'concurrent' => 6], Setting::get('calling.limits'));
    }
}
