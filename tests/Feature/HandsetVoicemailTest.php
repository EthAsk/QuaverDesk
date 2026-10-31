<?php

namespace Tests\Feature;

use App\Models\Mailbox;
use App\Models\Recording;
use App\Models\Sound;
use App\Models\User;
use App\Support\RecordingStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Symfony\Component\Process\ExecutableFinder;
use Tests\TestCase;

/** *00 from a phone: play, replay and delete voicemail, and record greetings. */
class HandsetVoicemailTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $sam;
    private Mailbox $own;
    private Mailbox $sales;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->owner()->create(['name' => 'Alex Owner']);
        $this->sam = User::factory()->create(['name' => 'Sam']);
        $this->acknowledgeTerms($this->owner);

        $this->own = Mailbox::query()->create(['name' => 'Sam', 'greeting' => 'Leave a message.']);
        $this->own->members()->attach($this->sam);
        $this->sales = Mailbox::query()->create(['name' => 'Sales', 'greeting' => 'Leave a message.']);
        $this->sales->members()->attach([$this->sam->id, $this->owner->id]);
    }

    protected function tearDown(): void
    {
        Sound::query()->each(fn (Sound $s) => $s->delete());
        parent::tearDown();
    }

    private function message(Mailbox $box, string $from, string $audio = 'RIFF-audio'): Recording
    {
        $r = Recording::query()->create(['kind' => Recording::VOICEMAIL, 'mailbox_id' => $box->id, 'carrier_sid' => bin2hex(random_bytes(8)), 'from' => $from, 'duration_seconds' => 3]);
        app(RecordingStore::class)->put($r, $audio);

        return $r;
    }

    private function dial(User $user, int $n): string
    {
        return $this->voicePost('/webhooks/voice/outgoing', [
            'session_id' => self::callId($n), 'from' => 'user-'.$user->id, 'Caller-Destination-Number' => '*00',
        ])->getContent();
    }

    private function press(int $n, string $query, ?string $digits): string
    {
        return $this->voicePost('/webhooks/voice/voicemail-menu?'.$query, array_filter(['session_id' => self::callId($n), 'digits' => $digits]))->getContent();
    }

    public function test_a_person_plays_replays_and_deletes_their_messages(): void
    {
        $first = $this->message($this->own, '+447700900123', 'first-audio');
        $second = $this->message($this->own, 'user-'.$this->owner->id);

        $list = $this->dial($this->sam, 1);
        $this->assertStringContainsString('For Sales, press 1. For Sam, press 2.', $list);

        $menu = $this->press(1, 'step=choose', '2');
        $this->assertStringContainsString('Sam. You have 2 messages. To listen, press 1. To record the greeting, press 2.', $menu);

        $play = $this->press(1, "step=box&box={$this->own->id}", '1');
        $this->assertStringContainsString('Message 1 of 2, from 0 7 7 0 0 9 0 0 1 2 3, today at', $play);
        $this->assertStringContainsString('To hear it again, press 1. For the next message, press 2. To delete it, press 7.', $play);
        $this->assertMatchesRegularExpression('#playback file="(http[^"]+/voice/messages/'.$first->id.'-\d+-[0-9a-f]{40}\.wav)"#', $play);

        // The voice server fetches the decrypted audio from the signed address; a changed one gets nothing.
        preg_match('#playback file="http://[^/]+([^"]+)"#', $play, $m);
        $this->get($m[1])->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertSee('first-audio');
        $this->get(str_replace('/voice/messages/'.$first->id.'-', '/voice/messages/'.$second->id.'-', $m[1]))->assertNotFound();
        $this->travel(16)->minutes();
        $this->get($m[1])->assertNotFound();
        $this->travelBack();

        $this->assertStringContainsString('Message 1 of 2', $this->press(1, "step=message&box={$this->own->id}&message={$first->id}", '1'));
        $this->assertStringContainsString('Message 2 of 2, from Alex Owner', $this->press(1, "step=message&box={$this->own->id}&message={$first->id}", '2'));

        $deleted = $this->press(1, "step=message&box={$this->own->id}&message={$first->id}", '7');
        $this->assertStringContainsString('Deleted.', $deleted);
        $this->assertStringContainsString('Message 1 of 1', $deleted);
        $this->assertNull(Recording::query()->find($first->id));

        $this->assertStringContainsString('There are no more messages.', $this->press(1, "step=message&box={$this->own->id}&message={$second->id}", '2'));
    }

    public function test_only_admins_record_a_shared_boxs_greeting(): void
    {
        $this->dial($this->sam, 2);
        $menu = $this->press(2, 'step=choose', '1');
        $this->assertStringContainsString('Sales. You have no messages.', $menu);
        $this->assertStringNotContainsString('record the greeting', $menu);
        $this->assertStringNotContainsString('<record', $this->press(2, "step=box&box={$this->sales->id}", '2'));

        $this->dial($this->owner, 3); // an admin, with one box
        $admin = $this->press(3, "step=box&box={$this->sales->id}", '2');
        $this->assertMatchesRegularExpression('#<record [^>]*action="[^"]+/webhooks/voice/greeting-recorded\?box='.$this->sales->id.'"#', $admin);
    }

    public function test_the_query_string_cannot_open_someone_elses_box(): void
    {
        $this->message($this->own, '+447700900123');
        $outsider = User::factory()->create();
        $this->dial($outsider, 4);

        $xml = $this->press(4, "step=box&box={$this->own->id}", '1');
        $this->assertStringNotContainsString('Message 1', $xml);
        $this->assertStringContainsString("You don't have a voicemail box yet.", $xml);

        // A call that isn't a *00 call gets nothing.
        $this->assertStringNotContainsString('Message', $this->press(99, "step=box&box={$this->own->id}", '1'));
    }

    public function test_a_greeting_recorded_on_the_phone_becomes_the_boxs_greeting(): void
    {
        $ffmpeg = (new ExecutableFinder())->find('ffmpeg');
        if ($ffmpeg === null) {
            $this->markTestSkipped('ffmpeg is not installed.');
        }
        config(['voip.ffmpeg' => $ffmpeg]);
        $this->sales->members()->detach($this->sam); // just their own box now

        $this->assertStringContainsString('Sam. You have no messages. To record the greeting, press 2.', $this->dial($this->sam, 5));
        $this->assertStringContainsString('Record the greeting for Sam after the tone.', $this->press(5, "step=box&box={$this->own->id}", '2'));

        $saved = $this->voicePost("/webhooks/voice/greeting-recorded?box={$this->own->id}", ['session_id' => self::callId(5)], [
            'recording' => UploadedFile::fake()->createWithContent('voicemail.wav', self::wav(3)),
        ])->getContent();
        $this->assertStringContainsString('Saved. Callers will now hear this greeting.', $saved);

        $sound = Sound::query()->sole();
        $this->assertStringStartsWith('Greeting for Sam, recorded on the phone', $sound->name);
        $this->assertSame($sound->id, $this->own->fresh()->greeting_sound_id);
        $this->assertStringContainsString('playback file="'.$sound->url().'"', $saved);
    }
}
