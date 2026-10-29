<?php

namespace Tests\Feature;

use App\Jobs\WarmPrompts;
use App\Models\Mailbox;
use App\Models\Setting;
use App\Models\Sound;
use App\Models\User;
use App\Support\DatabaseRoutingDirectory;
use App\Voice\VoiceServer;
use App\Voice\Voices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\ExecutableFinder;
use Tests\TestCase;

class SoundsAndVoiceTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = storage_path('framework/testing/sounds-'.bin2hex(random_bytes(4)));
        File::makeDirectory($this->dir, 0755, true);
        $owner = User::factory()->owner()->withTwoFactor()->create();
        $this->actingAs($owner);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        Sound::query()->each(fn (Sound $s) => $s->delete()); // removes their files
        parent::tearDown();
    }

    /** A sound without running ffmpeg. */
    private function sound(string $name = 'Greeting'): Sound
    {
        $file = bin2hex(random_bytes(20));
        File::ensureDirectoryExists(dirname(Sound::pathFor($file)));
        file_put_contents(Sound::pathFor($file), self::wav(1));

        return Sound::create(['name' => $name, 'file' => $file, 'seconds' => 1]);
    }

    public function test_an_uploaded_recording_becomes_a_voicemail_greeting(): void
    {
        $ffmpeg = (new ExecutableFinder())->find('ffmpeg');
        if ($ffmpeg === null) {
            $this->markTestSkipped('ffmpeg is not installed.');
        }
        config(['voip.ffmpeg' => $ffmpeg]);

        $path = $this->dir.'/upload.wav';
        file_put_contents($path, self::wav(2));

        $this->post('/admin/sounds', ['name' => 'Out of hours', 'audio' => new UploadedFile($path, 'my greeting.wav', null, null, true)])
            ->assertSessionHasNoErrors();

        $sound = Sound::query()->sole();
        $this->assertSame('Out of hours', $sound->name);
        $this->assertEqualsWithDelta(2.0, $sound->seconds, 0.2);
        $this->assertSame(16000, unpack('V', substr(file_get_contents($sound->path()), 24, 4))[1], 'converted to 16 kHz');

        $this->get(route('voice.sound', ['file' => $sound->file]))->assertOk()->assertHeader('Content-Type', 'audio/wav');

        $box = Mailbox::create(['name' => 'Main', 'greeting' => 'Please leave a message.', 'max_seconds' => 60]);
        $this->put("/admin/mailboxes/{$box->id}", [
            'name' => 'Main', 'greeting' => 'Please leave a message.', 'greeting_sound_id' => $sound->id, 'max_seconds' => 60,
        ])->assertSessionHasNoErrors();

        $config = app(DatabaseRoutingDirectory::class)->mailbox($box->id);
        $this->assertSame($sound->url(), $config->greetingSound);
    }

    public function test_files_that_are_not_audio_are_refused(): void
    {
        $path = $this->dir.'/playlist.mp3';
        file_put_contents($path, "#EXTM3U\n#EXTINF:1,\nfile:///etc/passwd\n");

        $this->post('/admin/sounds', ['name' => 'Sneaky', 'audio' => new UploadedFile($path, 'song.mp3', 'audio/mpeg', null, true)])
            ->assertSessionHasErrors('audio');

        $this->assertSame(0, Sound::query()->count());
    }

    public function test_hold_music_is_given_to_the_voice_server(): void
    {
        Queue::fake();
        config([
            'voip.freeswitch.conf_dir' => $this->dir.'/conf',
            'voip.freeswitch.domain' => 'voice.test',
            'voip.freeswitch.webhook_secret' => str_repeat('a1', 10),
            'voip.freeswitch.esl_password' => null,
        ]);
        $music = $this->sound('Jazz');

        $this->put('/admin/sounds/hold-music', ['sound_id' => $music->id])->assertSessionHasNoErrors();
        app(VoiceServer::class)->sync();
        $this->assertStringContainsString('data="hold_music='.$music->url().'"', file_get_contents($this->dir.'/conf/vars/quaverdesk.xml'));

        // Deleting it turns hold music off rather than playing a missing file.
        $this->delete("/admin/sounds/{$music->id}")->assertSessionHasNoErrors();
        $this->assertNull(Setting::get('sounds.hold_music'));
        $this->assertFileDoesNotExist($music->path());
        app(VoiceServer::class)->sync();
        $this->assertStringContainsString('data="hold_music=silence"', file_get_contents($this->dir.'/conf/vars/quaverdesk.xml'));
    }

    public function test_deleting_a_recording_puts_the_spoken_greeting_back(): void
    {
        $sound = $this->sound();
        $box = Mailbox::create(['name' => 'Main', 'greeting' => 'Leave a message.', 'greeting_sound_id' => $sound->id, 'max_seconds' => 60]);

        $this->get('/admin/sounds')->assertOk()->assertSee('Voicemail: Main');
        $this->delete("/admin/sounds/{$sound->id}");

        $this->assertNull($box->fresh()->greeting_sound_id);
        $this->assertNull(app(DatabaseRoutingDirectory::class)->mailbox($box->id)->greetingSound);
    }

    public function test_admins_hear_and_choose_a_voice(): void
    {
        Queue::fake();
        foreach (['onnx', 'onnx.json'] as $ext) {
            file_put_contents("{$this->dir}/en_GB-test_voice-medium.{$ext}", '{}');
        }
        config(['voip.tts.driver' => 'freeswitch', 'voip.tts.piper.voices' => $this->dir, 'voip.tts.kokoro.models' => null]);

        $this->get('/admin/voice')->assertOk()
            ->assertSee('Test Voice')
            ->assertSee('data-voice-play="piper:en_GB-test_voice-medium"', false)
            ->assertDontSee('data-voice-play="freeswitch"', false);

        $this->put('/admin/voice', ['voice' => 'piper:en_GB-made-up', 'speed' => 1])->assertSessionHasErrors('voice');
        $this->put('/admin/voice', ['voice' => 'piper:en_GB-test_voice-medium', 'speed' => 1.1])->assertSessionHasNoErrors();

        $voices = app(Voices::class);
        $this->assertSame('piper:en_GB-test_voice-medium', $voices->current());
        $this->assertSame($this->dir.'/en_GB-test_voice-medium.onnx', $voices->config()['piper']['voice']);
        $this->assertSame(1.1, $voices->speed());
        Queue::assertPushed(WarmPrompts::class, fn (WarmPrompts $job) => $job->texts === null);
    }

    public function test_the_built_in_voice_cannot_be_previewed(): void
    {
        $this->postJson('/admin/voice/preview', ['voice' => 'freeswitch', 'speed' => 1, 'text' => 'Hello', 'line' => 'phone'])
            ->assertStatus(422);
    }

    public function test_people_who_are_not_admins_cannot_change_sounds_or_voice(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/admin/sounds')->assertForbidden();
        $this->put('/admin/voice', ['voice' => 'freeswitch', 'speed' => 1])->assertForbidden();
    }
}
