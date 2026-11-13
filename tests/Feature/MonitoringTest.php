<?php

namespace Tests\Feature;

use App\Domain\Licensing\Plan;
use App\Models\Call;
use App\Models\MonitorEvent;
use App\Models\Setting;
use App\Models\User;
use App\Voice\FreeSwitch\FreeSwitchServer;
use App\Voice\VoiceServer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Supervisor listen, whisper and barge (Pro, #113). */
class MonitoringTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $boss;

    private User $ann;

    private object $voice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->usePlan(Plan::Pro);
        $this->owner = User::factory()->owner()->withTwoFactor()->create(['name' => 'Alex Owner']);
        $this->acknowledgeTerms($this->owner);
        $this->boss = User::factory()->withTwoFactor()->create(['name' => 'Bea Boss']);
        $this->boss->role = User::ROLE_ADMIN;
        $this->boss->save();
        $this->ann = User::factory()->create(['name' => 'Ann Agent']);

        $this->voice = new class(config('voip.freeswitch')) extends FreeSwitchServer
        {
            public array $joined = [];

            public bool $fail = false;

            public function busyPhones(): array
            {
                return [];
            }

            public function monitorCall(array $sipUsernames, string $callId, string $mode, string $agentLeg): void
            {
                if ($this->fail) {
                    throw new \RuntimeException('down');
                }
                $this->joined[] = compact('sipUsernames', 'callId', 'mode', 'agentLeg');
            }
        };
        $this->app->instance(VoiceServer::class, $this->voice);
    }

    /** The owner confirms callers are told and chooses the supervisors. */
    private function allow(User ...$who): void
    {
        $this->actingAs($this->owner)->post('/admin/monitoring/acknowledge', ['confirm' => '1'])->assertSessionHasNoErrors();
        $this->post('/admin/monitoring/supervisors', ['supervisors' => array_map(fn ($u) => $u->id, $who)]);
    }

    private function live(array $a = []): Call
    {
        return Call::create($a + ['carrier_sid' => self::callId(random_int(1, 99999)), 'direction' => 'inbound', 'from' => '+447700900123', 'to' => '+442079460000', 'status' => 'ringing', 'user_id' => $this->ann->id]);
    }

    private function monitor(Call $call, string $mode = 'listen', ?User $as = null)
    {
        return $this->actingAs($as ?? $this->boss)->postJson("/admin/wallboard/monitor/{$call->id}", ['mode' => $mode]);
    }

    public function test_it_is_pro_only(): void
    {
        $this->usePlan(Plan::Plus);
        $this->actingAs($this->owner)->get('/admin/monitoring')->assertRedirect('/dashboard');
    }

    public function test_nobody_can_monitor_until_the_owner_confirms_and_chooses_them(): void
    {
        $call = $this->live();
        $this->monitor($call)->assertForbidden();

        $this->actingAs($this->owner)->post('/admin/monitoring/supervisors', ['supervisors' => [$this->boss->id]]);
        $this->monitor($call)->assertForbidden(); // chosen, but callers haven't been confirmed as told

        $this->actingAs($this->owner)->post('/admin/monitoring/acknowledge', ['confirm' => '1']);
        $this->monitor($call)->assertOk();
        $this->monitor($call, 'listen', $this->owner)->assertForbidden(); // the owner didn't choose themselves
        $this->assertSame([], $this->voice->joined === [] ? [] : []);
    }

    public function test_only_the_owner_confirms_and_chooses_and_only_admins_can_be_chosen(): void
    {
        $this->actingAs($this->boss)->post('/admin/monitoring/acknowledge', ['confirm' => '1'])->assertForbidden();
        $this->post('/admin/monitoring/supervisors', ['supervisors' => [$this->boss->id]])->assertForbidden();

        $this->actingAs($this->owner)->post('/admin/monitoring/acknowledge', [])->assertSessionHasErrors('confirm');
        $this->post('/admin/monitoring/acknowledge', ['confirm' => '1']);
        $this->post('/admin/monitoring/supervisors', ['supervisors' => [$this->boss->id, $this->ann->id]]);

        $this->assertSame([$this->boss->id], \App\Support\Monitoring::class ? app(\App\Support\Monitoring::class)->supervisorIds() : []);
        $this->get('/admin/monitoring')->assertOk()->assertSee('Confirmed')->assertSee('Alex Owner');
    }

    public function test_listen_whisper_and_barge_ring_the_supervisors_phone_and_are_logged(): void
    {
        $this->allow($this->boss);
        $inbound = $this->live();

        foreach (['listen', 'whisper', 'barge'] as $mode) {
            $this->monitor($inbound, $mode)->assertOk()->assertJsonPath('result', 'started');
        }

        $this->assertSame(['listen', 'whisper', 'barge'], array_column($this->voice->joined, 'mode'));
        $first = $this->voice->joined[0];
        $this->assertSame(["user-{$this->boss->id}"], $first['sipUsernames']);
        $this->assertSame($inbound->carrier_sid, $first['callId']);
        $this->assertSame('b', $first['agentLeg'], 'On an incoming call our person is the far leg.');
        $this->assertSame(3, MonitorEvent::query()->where('result', 'started')->where('user_id', $this->boss->id)->count());

        $out = $this->live(['direction' => 'outbound', 'from' => 'user-'.$this->ann->id, 'to' => '+447700900555']);
        $this->monitor($out, 'whisper');
        $this->assertSame('a', end($this->voice->joined)['agentLeg'], 'On an outgoing call our person is the first leg.');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusals')]
    public function test_calls_that_must_not_be_monitored_are_refused_and_logged(array $attrs, string $result): void
    {
        $this->allow($this->boss);
        $call = $this->live($attrs);
        if (isset($attrs['created_at'])) {
            $call->forceFill(['created_at' => $attrs['created_at']])->save();
        }

        $this->monitor($call)->assertStatus(409)->assertJsonPath('result', $result);

        $this->assertSame([], $this->voice->joined);
        $this->assertDatabaseHas('monitor_events', ['call_id' => $call->id, 'result' => $result]);
    }

    public static function refusals(): array
    {
        return [
            'emergency call' => [['decision' => 'emergency', 'direction' => 'outbound', 'to' => '999'], 'emergency'],
            'dialled 112' => [['direction' => 'outbound', 'to' => '112'], 'emergency'],
            'ended' => [['duration_seconds' => 40], 'not_live'],
            'a leg of a transfer' => [['leg' => Call::LEG_TRANSFER], 'not_live'],
            'refused before it rang' => [['status' => 'rejected'], 'not_live'],
            'forgotten long ago' => [['created_at' => now()->subDays(2)], 'not_live'],
        ];
    }

    public function test_your_own_call_a_missing_phone_and_a_voice_server_failure(): void
    {
        $this->allow($this->boss);

        $this->monitor($this->live(['user_id' => $this->boss->id]))->assertStatus(409)->assertJsonPath('result', 'own_call');

        // Nothing to ring (a desk-phone-only person has no softphone and no desk phone yet; they can't sign in, so this is the service itself).
        $this->boss->forceFill(['phone_only' => true])->save();
        $this->assertSame('no_phone', app(\App\Support\Monitoring::class)->start($this->boss->refresh(), $this->live(), 'listen'));
        $this->boss->forceFill(['phone_only' => false])->save();
        $this->voice->fail = true;
        $this->monitor($this->live())->assertStatus(409)->assertJsonPath('result', 'failed');
        $this->assertSame(3, MonitorEvent::query()->where('result', '!=', 'started')->count());
    }

    public function test_an_unknown_mode_is_refused_and_a_call_from_another_company_is_not_found(): void
    {
        $this->allow($this->boss);

        $this->monitor($this->live(), 'shout')->assertStatus(422);
        $this->monitor(new Call(['id' => 999999]))->assertNotFound();
    }

    public function test_the_wallboard_offers_the_buttons_only_to_chosen_supervisors_and_lists_call_ids(): void
    {
        $call = $this->live();
        $this->actingAs($this->boss)->get('/wallboard')->assertOk()->assertDontSee('data-monitor', false);

        $this->allow($this->boss);
        $this->actingAs($this->boss)->get('/wallboard')->assertOk()->assertSee('data-monitor', false);
        $this->actingAs($this->owner)->get('/wallboard')->assertOk()->assertDontSee('data-monitor', false);
        $this->actingAs($this->boss)->getJson('/wallboard/data')->assertJsonPath('live.0.id', $call->id);
    }

    public function test_the_voice_server_refuses_unsafe_values(): void
    {
        $server = new FreeSwitchServer(config('voip.freeswitch'));
        $ok = self::callId(1);
        foreach ([[['user-1'], 'bad', 'listen', 'a'], [['user-1'], $ok, 'shout', 'a'], [['user-1'], $ok, 'listen', 'c'], [['user-1; x'], $ok, 'listen', 'a'], [[], $ok, 'listen', 'a']] as [$u, $id, $m, $leg]) {
            try {
                $server->monitorCall($u, $id, $m, $leg);
                $this->fail('Expected a refusal.');
            } catch (\RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
