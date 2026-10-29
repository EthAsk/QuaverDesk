<?php

namespace Tests\Feature;

use App\Ai\AiSettings;
use App\Domain\Licensing\Plan;
use App\Mail\VoicemailReceived;
use App\Models\Mailbox;
use App\Models\PhoneNumber;
use App\Models\Recording;
use App\Models\Setting;
use App\Models\User;
use App\Support\RecordingStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VoicemailAndRecordingTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Mailbox $box;


    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Mail::fake();
        Http::fake([
            'api.openai.com/*' => Http::response(['text' => 'Hi, please call me back about the quote.']),
            'api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => '{"summary":"Wants a callback about the quote."}']]]),
        ]);

        $this->owner = User::factory()->owner()->withTwoFactor()->create();
        $this->acknowledgeTerms($this->owner);

        $this->box = Mailbox::create(['name' => 'Office', 'greeting' => 'Leave a message.', 'max_seconds' => 60]);
        $this->box->members()->sync([$this->owner->id]);
        $this->owner->forceFill(['mailbox_id' => $this->box->id])->save();
    }

    private function leaveVoicemail(): void
    {
        $xml = $this->voicePost("/webhooks/voice/voicemail-saved?box=voicemail:{$this->box->id}", [
            'session_id' => self::callId(100),
        ], ['recording' => UploadedFile::fake()->createWithContent('voicemail.wav', self::wav(14))])->getContent();

        $this->assertStringContainsString('Your message has been recorded', $xml);
    }

    private function recording(): Recording
    {
        return Recording::firstWhere('carrier_sid', self::callId(100).'-voicemail');
    }

    public function test_unanswered_call_goes_to_the_persons_voicemail(): void
    {
        $xml = $this->voicePost("/webhooks/voice/after-dial?next=voicemail:{$this->box->id}&hop=0", [
            'session_id' => self::callId(99), 'originate_disposition' => 'NO_ANSWER',
        ])->getContent();

        $this->assertStringContainsString('Leave a message.', $xml);
        $this->assertMatchesRegularExpression('#<record file="voicemail.wav" name="recording" limit="60" [^>]*action="[^"]+voicemail-saved\?box=voicemail%3A\d+"/>#', $xml);
    }

    public function test_voicemail_is_stored_encrypted_and_emailed_on_free(): void
    {
        $this->leaveVoicemail();

        $recording = $this->recording();
        $this->assertSame('stored', $recording->status);
        $this->assertSame(14, $recording->duration_seconds);
        $this->assertNotSame(self::wav(14), Storage::disk('local')->get($recording->path), 'audio must be encrypted at rest');
        $this->assertSame(self::wav(14), app(RecordingStore::class)->get($recording));
        $this->assertSame([], Storage::disk('local')->allFiles('incoming'), 'plain upload must be removed');
        $this->assertNull($recording->transcript, 'no AI on Free');

        Mail::assertSent(VoicemailReceived::class, fn ($m) => $m->hasTo($this->owner->email) && count($m->attachments()) === 1);
    }

    public function test_plus_voicemail_is_transcribed_and_summarised_by_own_ai(): void
    {
        $this->usePlan(Plan::Plus);
        app(AiSettings::class)->save([
            'transcription' => ['base_url' => 'https://api.openai.com/v1', 'model' => 'whisper-1', 'api_key' => 'sk-test'],
            'analysis' => ['provider' => 'anthropic', 'base_url' => null, 'model' => 'claude-haiku-5-5', 'api_key' => 'sk-ant-test'],
        ]);

        $this->leaveVoicemail();

        $recording = $this->recording();
        $this->assertSame('done', $recording->ai_status);
        $this->assertSame('Hi, please call me back about the quote.', $recording->transcript);
        $this->assertSame('Wants a callback about the quote.', $recording->summary);

        Http::assertSent(fn ($req) => str_contains($req->url(), 'api.anthropic.com')
            && $req->header('anthropic-version')[0] === '2023-06-01'
            && $req['model'] === 'claude-haiku-5-5');
        Mail::assertSent(VoicemailReceived::class);
    }

    public function test_api_keys_are_encrypted_in_the_database(): void
    {
        app(AiSettings::class)->save([
            'transcription' => ['base_url' => 'https://api.openai.com/v1', 'model' => 'whisper-1', 'api_key' => 'sk-very-secret'],
            'analysis' => ['provider' => 'anthropic', 'base_url' => null, 'model' => 'm', 'api_key' => null],
        ]);

        $raw = Setting::query()->where('key', 'ai')->value('value');
        $this->assertStringNotContainsString('sk-very-secret', $raw);
        $this->assertSame('sk-very-secret', app(AiSettings::class)->all()['transcription']['api_key']);
    }

    public function test_call_recordings_are_ignored_on_free(): void
    {
        $this->voicePost('/webhooks/voice/recording-saved', ['session_id' => self::callId(200), 'exiting' => 'true'], [
            'recording' => UploadedFile::fake()->createWithContent('call.wav', self::wav(30)),
        ])->assertNoContent();

        $this->assertDatabaseCount('recordings', 0);
    }

    public function test_call_recording_is_saved_when_the_call_ends(): void
    {
        $this->usePlan(Plan::Plus);

        $this->voicePost('/webhooks/voice/recording-saved', ['session_id' => self::callId(201), 'exiting' => 'true'], [
            'recording' => UploadedFile::fake()->createWithContent('call.wav', self::wav(30)),
        ])->assertNoContent();

        $recording = Recording::firstWhere('carrier_sid', self::callId(201).'-call');
        $this->assertSame('stored', $recording->status);
        $this->assertSame(30, $recording->duration_seconds);
    }

    public function test_long_call_recordings_are_stored_compressed(): void
    {
        $ffmpeg = (new \Symfony\Component\Process\ExecutableFinder())->find('ffmpeg');
        if ($ffmpeg === null) {
            $this->markTestSkipped('ffmpeg is not installed.');
        }
        config(['voip.ffmpeg' => $ffmpeg]);
        $this->usePlan(Plan::Plus);

        $this->voicePost('/webhooks/voice/recording-saved', ['session_id' => self::callId(202), 'exiting' => 'true'], [
            'recording' => UploadedFile::fake()->createWithContent('call.wav', self::wav(60)),
        ])->assertNoContent();

        $recording = Recording::firstWhere('carrier_sid', self::callId(202).'-call');
        $this->actingAs($this->owner)->get(route('recordings.audio', $recording))
            ->assertOk()
            ->assertHeader('Content-Type', 'audio/mp4')
            ->assertHeader('Content-Disposition', 'inline; filename="recording-'.$recording->id.'.m4a"');
        $this->assertLessThan(strlen(self::wav(60)) / 4, strlen(app(\App\Support\RecordingStore::class)->get($recording)));
    }

    public function test_recorded_number_announces_and_records(): void
    {
        $this->usePlan(Plan::Plus);
        PhoneNumber::create(['e164' => '+442079460000', 'destination' => "user:{$this->owner->id}", 'record_calls' => true]);

        $xml = $this->voicePost('/webhooks/voice/inbound', ['session_id' => self::callId(300), 'Caller-Destination-Number' => '+442079460000'])->getContent();

        $this->assertStringContainsString('may be recorded', $xml);
        $this->assertMatchesRegularExpression('#<recordCall name="recording" limit="14400" action="[^"]+recording-saved"/><execute application="bridge"#', $xml);
        $this->assertStringContainsString('rec=1', $xml);
    }

    public function test_other_users_cannot_see_or_play_a_recording(): void
    {
        $this->leaveVoicemail();
        $recording = Recording::first();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->get(route('recordings.show', $recording))->assertNotFound();
        $this->actingAs($stranger)->get(route('recordings.audio', $recording))->assertNotFound();

        $this->actingAs($this->owner)->get(route('recordings.audio', $recording))
            ->assertOk()->assertHeader('Content-Type', 'audio/wav');
    }

    public function test_ai_button_is_locked_on_free(): void
    {
        $this->leaveVoicemail();

        $this->actingAs($this->owner)->post(route('recordings.analyse', Recording::first()))
            ->assertSessionHas('upgrade');
    }

    public function test_expired_recordings_are_pruned(): void
    {
        $this->leaveVoicemail();
        $this->travel(91)->days(); // Plus keeps recordings 90 days

        $this->artisan('recordings:prune')->assertSuccessful();

        $this->assertDatabaseCount('recordings', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('recordings'));
    }

    public function test_a_new_retention_period_applies_to_recordings_already_kept(): void
    {
        $this->usePlan(Plan::Pro);
        Setting::put('recording.retention_days', 365);
        $this->leaveVoicemail();
        $this->travel(100)->days();

        $this->artisan('recordings:prune')->assertSuccessful();
        $this->assertDatabaseCount('recordings', 1); // 100 days old, kept for a year

        Setting::put('recording.retention_days', 30);
        $this->artisan('recordings:prune')->assertSuccessful();
        $this->assertDatabaseCount('recordings', 0);
    }
}
