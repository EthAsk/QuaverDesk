<?php

namespace Tests\Feature;

use App\Domain\Licensing\Plan;
use App\Models\Call;
use App\Models\CallQueue;
use App\Models\PhoneNumber;
use App\Models\QueueCaller;
use App\Models\User;
use App\Voice\FreeSwitch\FreeSwitchServer;
use App\Voice\VoiceServer;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Queue strategies: who gets the call (#114). */
class QueueStrategyTest extends TestCase
{
    use RefreshDatabase;

    private User $ann;

    private User $ben;

    private User $cat;

    private CallQueue $queue;

    private object $voice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->usePlan(Plan::Plus);
        $owner = User::factory()->owner()->withTwoFactor()->create();
        $this->acknowledgeTerms($owner);
        [$this->ann, $this->ben, $this->cat] = [User::factory()->create(['name' => 'Ann']), User::factory()->create(['name' => 'Ben']), User::factory()->create(['name' => 'Cat'])];

        $this->voice = new class(config('voip.freeswitch')) extends FreeSwitchServer
        {
            public array $busy = [];

            public function busyPhones(): array
            {
                return $this->busy;
            }
        };
        $this->app->instance(VoiceServer::class, $this->voice);

        $this->queue = CallQueue::create(['name' => 'Support', 'ring_seconds' => 15, 'max_wait_seconds' => 300]);
        $this->queue->members()->attach([$this->ann->id => ['priority' => 2], $this->ben->id => ['priority' => 1], $this->cat->id => ['priority' => 1]]);
        PhoneNumber::create(['e164' => '+442079460000', 'destination' => "queue:{$this->queue->id}"]);
    }

    private function round(int $call, int $step = 0, string $query = ''): string
    {
        return $this->voicePost("/webhooks/voice/queue?queue=queue:{$this->queue->id}".($step ? ":{$step}" : '').'&hop=0'.$query, [
            'session_id' => self::callId($call), 'Caller-Caller-ID-Number' => '+447700900123',
        ])->assertOk()->getContent();
    }

    /** The callers so far have been answered, so they no longer wait ahead of the next. */
    private function clear(): void
    {
        QueueCaller::query()->update(['ended_at' => now(), 'outcome' => QueueCaller::ANSWERED]);
    }

    /** Which agents a round rings: "ann,ben". */
    private function rung(string $xml): string
    {
        preg_match_all('#user/user-(\d+)@#', $xml, $m);

        return collect($m[1])->map(fn ($id) => ['Ann' => $this->ann, 'Ben' => $this->ben, 'Cat' => $this->cat])->count() ? collect($m[1])->map(fn ($id) => User::query()->find($id)->name)->unique()->sort()->implode(',') : '';
    }

    private function finished(User $user, string $at): void
    {
        $c = Call::create(['carrier_sid' => 'c-'.uniqid(), 'user_id' => $user->id, 'direction' => 'inbound', 'status' => 'completed', 'duration_seconds' => 30]);
        $c->forceFill(['updated_at' => CarbonImmutable::parse($at), 'created_at' => CarbonImmutable::parse($at)])->save();
    }

    public function test_the_default_still_rings_everyone(): void
    {
        $this->assertSame('Ann,Ben,Cat', $this->rung($this->round(1)));
    }

    public function test_longest_idle_rings_the_one_who_finished_a_call_longest_ago_and_tries_someone_else_next_round(): void
    {
        $this->queue->update(['strategy' => 'longest_idle']);
        $this->finished($this->ann, now()->subHour()->toDateTimeString());
        $this->finished($this->ben, now()->subMinutes(5)->toDateTimeString());
        // Cat has never had a call: idle the longest.

        $this->assertSame('Cat', $this->rung($this->round(1)));
        $this->assertSame('Ann', $this->rung($this->round(1, 1, '&rang=1')), 'Cat did not answer, so the next longest idle.');
        $this->assertSame('Ben', $this->rung($this->round(1, 2, '&rang=1')));
        $this->assertSame('Cat', $this->rung($this->round(1, 3, '&rang=1')), 'Everyone has been tried: start again.');
    }

    public function test_round_robin_moves_on_from_whoever_was_rung_last(): void
    {
        $this->queue->update(['strategy' => 'round_robin']);

        foreach ([1 => 'Ann', 2 => 'Ben', 3 => 'Cat', 4 => 'Ann'] as $call => $expected) {
            $this->assertSame($expected, $this->rung($this->round($call)));
            $this->clear();
        }
    }

    public function test_fewest_calls_today(): void
    {
        $this->queue->update(['strategy' => 'fewest_calls']);
        $this->finished($this->ann, now()->toDateTimeString());
        $this->finished($this->ann, now()->toDateTimeString());
        $this->finished($this->ben, now()->toDateTimeString());
        $this->finished($this->cat, now()->subDays(3)->toDateTimeString()); // not today

        $this->assertSame('Cat', $this->rung($this->round(1)));
    }

    public function test_busy_agents_are_never_chosen(): void
    {
        $this->queue->update(['strategy' => 'longest_idle']);
        $this->voice->busy = ["user-{$this->ann->id}", "user-{$this->cat->id}"];

        $this->assertSame('Ben', $this->rung($this->round(1)));
    }

    public function test_priority_rings_first_choices_then_backups_and_needs_pro(): void
    {
        $this->queue->update(['strategy' => 'priority']);

        // On Plus it quietly rings everyone.
        $this->assertSame('Ann,Ben,Cat', $this->rung($this->round(1)));
        $this->clear();

        $this->usePlan(Plan::Pro);
        $this->assertSame('Ben,Cat', $this->rung($this->round(2)), 'First choices (priority 1) ring together.');
        $this->clear();

        $this->voice->busy = ["user-{$this->ben->id}", "user-{$this->cat->id}"];
        $this->assertSame('Ann', $this->rung($this->round(3)), 'Both first choices are busy: the backup rings.');
    }

    public function test_the_form_saves_the_strategy_and_priorities_only_on_pro(): void
    {
        $owner = User::query()->where('role', User::ROLE_OWNER)->first();
        $payload = fn (array $extra = []) => $extra + ['name' => 'Support', 'ring_seconds' => 15, 'max_wait_minutes' => 5, 'strategy' => 'priority',
            'members' => [$this->ann->id, $this->ben->id, $this->cat->id], 'priority' => [$this->ann->id => 3, $this->ben->id => 1, $this->cat->id => 2]];

        $this->actingAs($owner)->put("/admin/queues/{$this->queue->id}", $payload())->assertSessionHasNoErrors();
        $this->assertSame('priority', $this->queue->refresh()->strategy);
        $this->assertSame(2, (int) $this->queue->members()->whereKey($this->ann->id)->first()->pivot->priority, 'On Plus the priorities stay as they were.');

        $this->usePlan(Plan::Pro);
        $this->put("/admin/queues/{$this->queue->id}", $payload())->assertSessionHasNoErrors();
        $this->assertSame([3, 1, 2], $this->queue->members()->orderBy('users.id')->get()->map(fn ($u) => (int) $u->pivot->priority)->all());
        $this->get("/admin/queues/{$this->queue->id}")->assertOk()->assertSee('Priority');

        $this->put("/admin/queues/{$this->queue->id}", $payload(['strategy' => 'nonsense']))->assertSessionHasErrors('strategy');
    }
}
