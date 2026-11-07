<?php

namespace Tests\Feature;

use App\Domain\Licensing\Plan;
use App\Models\CallQueue;
use App\Models\Mailbox;
use App\Models\PhoneNumber;
use App\Models\QueueCaller;
use App\Models\Sound;
use App\Models\User;
use App\Voice\FreeSwitch\FreeSwitchServer;
use App\Voice\VoiceServer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Call queues: callers wait their turn with hold music while free agents are rung. */
class CallQueueTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $ann;
    private User $ben;
    private CallQueue $queue;
    private FreeSwitchServer $voice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->usePlan(Plan::Plus);
        $this->owner = User::factory()->owner()->withTwoFactor()->create(['name' => 'Alex Owner']);
        $this->acknowledgeTerms($this->owner);
        $this->ann = User::factory()->create(['name' => 'Ann Agent']);
        $this->ben = User::factory()->create(['name' => 'Ben Agent']);

        $this->voice = new class(config('voip.freeswitch')) extends FreeSwitchServer
        {
            public array $busy = [];

            public function busyPhones(): array
            {
                return $this->busy;
            }
        };
        $this->app->instance(VoiceServer::class, $this->voice);

        $this->queue = CallQueue::create(['name' => 'Support', 'greeting' => 'Thanks for calling support.', 'ring_seconds' => 15, 'max_wait_seconds' => 300]);
        $this->queue->members()->attach([$this->ann->id, $this->ben->id]);
        PhoneNumber::create(['e164' => '+442079460000', 'destination' => "queue:{$this->queue->id}"]);
    }

    private function round(int $call, int $step = 0, array $extra = [], string $query = ''): string
    {
        return $this->voicePost("/webhooks/voice/queue?queue=queue:{$this->queue->id}".($step ? ":{$step}" : '').'&hop=0'.$query, [
            'session_id' => self::callId($call), 'Caller-Caller-ID-Number' => '+447700900123',
        ] + $extra)->assertOk()->getContent();
    }

    public function test_a_call_to_the_number_joins_the_queue_and_rings_the_free_agents_with_hold_music(): void
    {
        Sound::create(['name' => 'Music', 'file' => str_repeat('a', 40), 'seconds' => 30]);
        $this->queue->update(['hold_sound_id' => Sound::query()->value('id')]);

        $xml = $this->voicePost('/webhooks/voice/inbound', ['session_id' => self::callId(1), 'Caller-Caller-ID-Number' => '+447700900123',
            'Caller-Destination-Number' => '+442079460000'])->getContent();
        $this->assertStringContainsString('webhooks/voice/queue?queue=queue%3A'.$this->queue->id, $xml);

        $xml = $this->round(1);
        $this->assertStringContainsString('application="answer"', $xml);
        $this->assertStringContainsString('Thanks for calling support.', $xml);
        $this->assertStringContainsString('You are first in the queue.', $xml);
        $this->assertStringContainsString("user/user-{$this->ann->id}@voice.test", $xml);
        $this->assertStringContainsString("user/user-{$this->ben->id}@voice.test", $xml);
        $this->assertStringContainsString('originate_timeout=15', $xml);
        $this->assertMatchesRegularExpression('#data="transfer_ringback=http[^"]+/voice/sounds/a{40}\.wav"#', $xml);
        $this->assertStringContainsString('rang=1', $xml);

        $caller = QueueCaller::query()->sole();
        $this->assertSame('+447700900123', $caller->from);
        $this->assertNotNull($caller->ringing_until);
    }

    public function test_callers_wait_their_turn_while_agents_are_busy(): void
    {
        $this->round(1); // first caller rings both agents
        $this->voice->busy = ["user-{$this->ann->id}"]; // Ann takes a call

        // Second caller: one free agent, but the first caller is still ahead of them.
        $xml = $this->round(2);
        $this->assertStringContainsString('You are number 2 in the queue.', $xml);
        $this->assertStringNotContainsString('bridge', $xml);
        $this->assertStringContainsString('application="sleep" data="20000"', $xml); // no hold music: silence
        $this->assertStringContainsString('queue%3A'.$this->queue->id.'%3A1', $xml);

        // The first caller was answered: the second is now first, and rings the free agent only.
        $this->round(1, 1, ['originate_disposition' => 'SUCCESS'], '&rang=1');
        $this->assertSame(QueueCaller::ANSWERED, QueueCaller::query()->where('carrier_sid', self::callId(1))->value('outcome'));

        $xml = $this->round(2, 1);
        $this->assertStringContainsString('You are first in the queue.', $xml);
        $this->assertStringNotContainsString('Thanks for calling support.', $xml); // only when joining
        $this->assertStringContainsString("user/user-{$this->ben->id}@voice.test", $xml);
        $this->assertStringNotContainsString("user/user-{$this->ann->id}@voice.test", $xml);
    }

    public function test_nobody_answering_means_holding_again_and_ringing_again(): void
    {
        $this->round(1);
        $xml = $this->round(1, 1, ['originate_disposition' => 'NO_ANSWER'], '&rang=1');

        $this->assertStringContainsString('bridge', $xml);
        $this->assertStringNotContainsString('You are first', $xml); // already told, and their place hasn't changed
        $this->assertNull(QueueCaller::query()->value('ended_at'));
    }

    public function test_the_longest_wait_and_an_empty_queue_go_to_the_overflow(): void
    {
        $box = Mailbox::create(['name' => 'Support', 'greeting' => 'Please leave a message for support.']);
        $this->queue->update(['overflow' => "voicemail:{$box->id}"]);

        $this->round(1);
        QueueCaller::query()->update(['joined_at' => now()->subMinutes(6)]);
        $xml = $this->round(1, 3, ['originate_disposition' => 'NO_ANSWER'], '&rang=1');
        $this->assertStringContainsString('Please leave a message for support.', $xml);
        $this->assertSame(QueueCaller::OVERFLOW, QueueCaller::query()->value('outcome'));

        // Everyone logged out: straight to the overflow (unless the queue holds callers anyway).
        $this->queue->members()->updateExistingPivot([$this->ann->id, $this->ben->id], ['logged_in' => false]);
        $this->assertStringContainsString('Please leave a message for support.', $this->round(2));

        $this->queue->update(['overflow_when_empty' => false]);
        $xml = $this->round(3);
        $this->assertStringContainsString('application="sleep"', $xml);
        $this->assertStringNotContainsString('leave a message', $xml);
    }

    public function test_hanging_up_leaves_the_queue(): void
    {
        $this->round(1);
        $this->round(2);
        QueueCaller::query()->update(['ringing_until' => now()->addSeconds(10)]);

        $this->voicePost('/webhooks/voice/queue?queue=queue:'.$this->queue->id, ['session_id' => self::callId(1), 'exiting' => 'true'])->assertNoContent();
        $this->assertSame(QueueCaller::ABANDONED, QueueCaller::query()->where('carrier_sid', self::callId(1))->value('outcome'));

        // A call that went on well past the ringing was answered.
        QueueCaller::query()->where('carrier_sid', self::callId(2))->update(['ringing_until' => now()->subMinute()]);
        $this->voicePost('/webhooks/voice/route', ['session_id' => self::callId(2), 'exiting' => 'true'])->assertNoContent();
        $this->assertSame(QueueCaller::ANSWERED, QueueCaller::query()->where('carrier_sid', self::callId(2))->value('outcome'));
    }

    public function test_agents_log_in_and_out_by_phone_and_on_the_dashboard(): void
    {
        $dial = fn (User $u) => $this->voicePost('/webhooks/voice/outgoing', ['session_id' => self::callId(random_int(100, 999)),
            'from' => $u->carrierIdentity(), 'Caller-Destination-Number' => '*45'])->getContent();

        $this->assertStringContainsString('logged out of your call queues', $dial($this->ann));
        $this->assertFalse((bool) $this->ann->queues()->sole()->pivot->logged_in);
        $this->assertStringContainsString('logged in to your call queues', $dial($this->ann));
        $this->assertStringContainsString('not an agent in any call queue', $dial($this->owner));

        $this->actingAs($this->ann)->get('/dashboard')->assertOk()->assertSee('Your call queues')->assertSee('Support');
        $this->actingAs($this->ann)->patch("/my-queues/{$this->queue->id}", ['logged_in' => 0])->assertRedirect();
        $this->assertFalse((bool) $this->ann->queues()->sole()->pivot->logged_in);
        $this->actingAs($this->owner)->patch("/my-queues/{$this->queue->id}", ['logged_in' => 1])->assertNotFound(); // not an agent
    }

    public function test_phones_dial_or_transfer_to_the_queue_by_its_extension(): void
    {
        $this->queue->update(['extension' => '600']);

        $xml = $this->voicePost('/webhooks/voice/outgoing', ['session_id' => self::callId(5), 'from' => $this->owner->carrierIdentity(),
            'Caller-Destination-Number' => '600'])->getContent();
        $this->assertStringContainsString('webhooks/voice/queue?queue=queue%3A'.$this->queue->id, $xml);
    }

    public function test_admins_set_up_queues_and_extensions_never_clash(): void
    {
        $admin = fn () => $this->actingAs($this->owner);

        $admin()->post('/admin/queues', ['name' => 'Sales', 'extension' => $this->ann->extension, 'ring_seconds' => 20, 'max_wait_minutes' => 5])
            ->assertSessionHasErrors('extension');
        $admin()->post('/admin/queues', ['name' => 'Sales', 'extension' => '650', 'ring_seconds' => 20, 'max_wait_minutes' => 4,
            'announce_position' => '1', 'overflow' => "user:{$this->owner->id}", 'members' => [$this->ben->id]])->assertSessionHasNoErrors();

        $sales = CallQueue::query()->firstWhere('name', 'Sales');
        $this->assertSame(240, $sales->max_wait_seconds);
        $this->assertTrue($sales->announce_position);
        $this->assertFalse($sales->overflow_when_empty);
        $this->assertSame([$this->ben->id], $sales->agents()->pluck('users.id')->all());

        // Ring groups and people can't take a queue's extension.
        $admin()->post('/admin/ring-groups', ['name' => 'G', 'extension' => '650', 'strategy' => 'simultaneous', 'ring_seconds' => 20])->assertSessionHasErrors('extension');
        $this->assertNotSame('650', User::factory()->create()->extension);

        // An admin logs an agent out; the page shows who's waiting.
        $admin()->patch("/admin/queues/{$sales->id}/agents/{$this->ben->id}", ['logged_in' => 0])->assertRedirect();
        $this->assertSame([], $sales->agents()->pluck('users.id')->all());
        $admin()->get('/admin/queues')->assertOk()->assertSee('Sales')->assertSee('0 of 1');
        $admin()->get("/admin/queues/{$sales->id}")->assertOk()->assertSee('Log agents in or out');
        $this->assertArrayHasKey("queue:{$sales->id}", app(\App\Support\DestinationCatalog::class)->options()['Call queues']);
    }

    public function test_queues_are_a_plus_feature(): void
    {
        \App\Models\Setting::forget('licence_key'); // no key: the Free plan
        $this->app->forgetScopedInstances();

        $this->assertStringContainsString('nobody is available', $this->round(1));
        $this->actingAs($this->owner)->get('/admin/queues')->assertRedirect();
        $this->assertSame([], app(\App\Support\DestinationCatalog::class)->options()['Call queues']);
    }
}
