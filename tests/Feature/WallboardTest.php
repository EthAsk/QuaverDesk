<?php

namespace Tests\Feature;

use App\Domain\Licensing\Plan;
use App\Models\Call;
use App\Models\CallQueue;
use App\Models\QueueCaller;
use App\Models\User;
use App\Voice\FreeSwitch\FreeSwitchServer;
use App\Voice\VoiceServer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/** Live wallboard (Pro, #29). */
class WallboardTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $ann;

    private User $ben;

    private CallQueue $queue;

    private object $voice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->usePlan(Plan::Pro);
        $this->owner = User::factory()->owner()->withTwoFactor()->create(['name' => 'Alex Owner']);
        $this->acknowledgeTerms($this->owner);
        $this->ann = User::factory()->create(['name' => 'Ann Agent']);
        $this->ben = User::factory()->create(['name' => 'Ben Agent']);

        $this->voice = new class(config('voip.freeswitch')) extends FreeSwitchServer
        {
            public ?array $busy = [];

            public function busyPhones(): array
            {
                return $this->busy ?? throw new RuntimeException('Event socket down');
            }
        };
        $this->app->instance(VoiceServer::class, $this->voice);

        $this->queue = CallQueue::create(['name' => 'Support', 'greeting' => 'Hi.', 'ring_seconds' => 15, 'max_wait_seconds' => 300]);
        $this->queue->members()->attach([$this->ann->id => ['logged_in' => true], $this->ben->id => ['logged_in' => true]]);
    }

    private function data(): array
    {
        return $this->actingAs($this->owner)->getJson('/wallboard/data')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json();
    }

    public function test_shows_live_calls_queues_and_who_is_busy(): void
    {
        Call::create(['carrier_sid' => self::callId(1), 'direction' => 'inbound', 'from' => '+447700900123', 'to' => '+442079460000', 'user_id' => $this->ann->id, 'status' => 'ringing']);
        Call::create(['carrier_sid' => self::callId(2), 'direction' => 'inbound', 'from' => '+447700900999', 'status' => 'no-answer', 'duration_seconds' => 20]);
        Call::create(['carrier_sid' => self::callId(3), 'direction' => 'outbound', 'status' => 'completed', 'duration_seconds' => 95, 'user_id' => $this->ben->id]);
        Call::create(['carrier_sid' => self::callId(4), 'direction' => 'inbound', 'status' => 'ringing', 'leg' => Call::LEG_FOLLOW_ME]);
        $this->travel(-1)->days();
        Call::create(['carrier_sid' => self::callId(5), 'direction' => 'inbound', 'status' => 'completed', 'duration_seconds' => 500]);
        $this->travelBack();

        $this->queue->callers()->create(['carrier_sid' => self::callId(6), 'from' => '+447700900555', 'joined_at' => now()->subSeconds(40), 'seen_at' => now()]);
        $this->queue->callers()->create(['carrier_sid' => self::callId(7), 'joined_at' => now()->subMinutes(5), 'seen_at' => now()->subMinutes(4), 'ended_at' => now()->subMinutes(4), 'outcome' => QueueCaller::ANSWERED]);
        $this->queue->callers()->create(['carrier_sid' => self::callId(8), 'joined_at' => now()->subMinutes(3), 'seen_at' => now()->subMinutes(2), 'ended_at' => now()->subMinutes(2), 'outcome' => QueueCaller::ABANDONED]);
        $this->voice->busy = ["user-{$this->ann->id}", 'user-999999'];

        $d = $this->data();

        $this->assertTrue($d['voice_server']);
        $this->assertSame(['inbound' => 2, 'outbound' => 1, 'missed' => 1, 'talk_seconds' => 95], $d['totals']);
        $this->assertCount(1, $d['live']); // the follow-me leg and ended calls aren't live
        $this->assertSame(['inbound', '+447700900123', 'Ann Agent'], [$d['live'][0]['direction'], $d['live'][0]['from'], $d['live'][0]['person']]);

        $q = $d['queues'][0];
        $this->assertSame(['Support', 1, 2, 1, 1, 1, 0], [$q['name'], $q['waiting'], $q['agents'], $q['agents_busy'], $q['answered'], $q['abandoned'], $q['overflowed']]);
        $this->assertGreaterThanOrEqual(40, $q['longest_wait']);

        $people = collect($d['people'])->keyBy('name');
        $this->assertSame('busy', $people['Ann Agent']['status']);
        $this->assertSame('free', $people['Ben Agent']['status']);
        $this->assertSame(['Support'], $people['Ben Agent']['queues']);
        $this->assertSame(1, $people['Ben Agent']['calls_today']);
    }

    public function test_still_works_when_the_voice_server_cannot_say_who_is_busy(): void
    {
        $this->voice->busy = null;
        $d = $this->data();

        $this->assertFalse($d['voice_server']);
        $this->assertNull($d['queues'][0]['agents_busy']);
        $this->assertSame('unknown', collect($d['people'])->firstWhere('name', 'Ann Agent')['status']);
    }

    public function test_the_page_carries_the_first_snapshot_safely(): void
    {
        Call::create(['carrier_sid' => self::callId(1), 'direction' => 'inbound', 'from' => '</script><script>alert(1)</script>', 'status' => 'ringing']);

        $this->actingAs($this->owner)->get('/wallboard')->assertOk()
            ->assertSee('data-wallboard', false)
            ->assertDontSee('</script><script>alert(1)', false);
    }

    public function test_everyone_can_see_it_but_only_on_pro(): void
    {
        $this->actingAs($this->ann)->get('/wallboard')->assertOk()->assertDontSee('data-monitor', false);
        $this->actingAs($this->ann)->getJson('/wallboard/data')->assertOk();
        $this->actingAs($this->ann)->get('/admin/wallboard')->assertRedirect('/wallboard');

        $this->usePlan(Plan::Plus);
        $this->actingAs($this->owner)->get('/wallboard')->assertRedirect(route('dashboard'));
        $this->actingAs($this->owner)->getJson('/wallboard/data')->assertStatus(402);
    }

    public function test_admins_can_hide_callers_numbers_from_everyone_else(): void
    {
        Call::create(['carrier_sid' => self::callId(7), 'direction' => 'inbound', 'from' => '+447700900123', 'to' => '+441522000000', 'status' => 'answered']);

        // Shown by default.
        $this->actingAs($this->ann)->getJson('/wallboard/data')->assertJsonPath('live.0.from', '+447700900123');
        $this->actingAs($this->ann)->get('/wallboard')->assertOk()->assertDontSee('Who sees what');

        $this->actingAs($this->ann)->put('/admin/wallboard/settings', ['hide_numbers' => 1])->assertForbidden();
        $this->actingAs($this->owner)->get('/wallboard')->assertOk()->assertSee('Who sees what');
        $this->actingAs($this->owner)->put('/admin/wallboard/settings', ['hide_numbers' => 1])->assertRedirect();

        $this->actingAs($this->ann)->getJson('/wallboard/data')
            ->assertJsonPath('live.0.from', null)->assertJsonPath('live.0.to', null)->assertJsonPath('live.0.direction', 'inbound');
        $this->actingAs($this->ann)->get('/wallboard')->assertOk()->assertDontSee('+447700900123');
        $this->actingAs($this->owner)->getJson('/wallboard/data')->assertJsonPath('live.0.from', '+447700900123');

        $this->actingAs($this->owner)->put('/admin/wallboard/settings', ['hide_numbers' => 0]);
        $this->actingAs($this->ann)->getJson('/wallboard/data')->assertJsonPath('live.0.from', '+447700900123');
    }
}
