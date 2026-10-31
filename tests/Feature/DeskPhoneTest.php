<?php

namespace Tests\Feature;

use App\Domain\Licensing\Plan;
use App\Jobs\SyncVoiceServer;
use App\Models\DeskPhone;
use App\Models\PhoneNumber;
use App\Models\SipTrunk;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DeskPhoneTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private DeskPhone $phone;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->owner()->withTwoFactor()->create();
        $this->acknowledgeTerms($this->owner);

        $this->phone = DeskPhone::create([
            'user_id' => $this->owner->id, 'label' => 'Reception', 'sip_username' => "u{$this->owner->id}-abc123",
            'sip_password' => 'Secret123456Abc',
        ]);
    }

    public function test_desk_phone_rings_with_softphone_on_pro(): void
    {
        $this->usePlan(Plan::Pro);
        PhoneNumber::create(['e164' => '+442079460000', 'destination' => "user:{$this->owner->id}"]);

        $xml = $this->voicePost('/webhooks/voice/inbound', ['session_id' => self::callId(1), 'Caller-Destination-Number' => '+442079460000'])->getContent();

        $this->assertStringContainsString("']user/user-{$this->owner->id}@voice.test,[execute_on_answer_qd='bridge_export nolocal:qd_by=u{$this->owner->id}-abc123']user/u{$this->owner->id}-abc123@voice.test\"", $xml);
    }

    public function test_desk_phone_does_not_ring_on_free(): void
    {
        PhoneNumber::create(['e164' => '+442079460000', 'destination' => "user:{$this->owner->id}"]);

        $xml = $this->voicePost('/webhooks/voice/inbound', ['session_id' => self::callId(2), 'Caller-Destination-Number' => '+442079460000'])->getContent();

        $this->assertStringNotContainsString("u{$this->owner->id}-abc123", $xml);
    }

    public function test_desk_phone_can_dial_out_on_pro(): void
    {
        $this->usePlan(Plan::Pro);
        SipTrunk::create([
            'name' => 'HQ', 'provider' => 'other', 'inbound_ips' => [['ip' => '203.0.113.10', 'prefix' => 32]],
            'origination_uris' => ['sip:sbc.example.net'], 'status' => 'active', 'default_outbound' => true,
        ]);

        $xml = $this->voicePost('/webhooks/voice/outgoing', [
            'session_id' => self::callId(3), 'from' => "u{$this->owner->id}-abc123", 'Caller-Destination-Number' => '07700900123',
        ])->getContent();

        $this->assertStringContainsString('/+447700900123"', $xml);
        $this->assertDatabaseHas('calls', ['carrier_sid' => self::callId(3), 'user_id' => $this->owner->id]);
    }

    public function test_unknown_sip_caller_is_rejected(): void
    {
        $this->usePlan(Plan::Pro);

        $xml = $this->voicePost('/webhooks/voice/outgoing', [
            'session_id' => self::callId(4), 'from' => 'intruder', 'Caller-Destination-Number' => '07700900123',
        ])->getContent();

        $this->assertStringNotContainsString('bridge', $xml);
    }

    public function test_admin_adds_phone_with_strong_password_stored_encrypted(): void
    {
        $this->usePlan(Plan::Pro);
        Queue::fake();

        $this->actingAs($this->owner)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post('/admin/desk-phones', ['user_id' => $this->owner->id, 'label' => 'Office'])
            ->assertSessionHasNoErrors();

        $phone = DeskPhone::firstWhere('label', 'Office');
        $raw = DB::table('desk_phones')->where('id', $phone->id)->value('sip_password');
        $this->assertNotSame($phone->sip_password, $raw, 'password must be encrypted at rest');
        $this->assertMatchesRegularExpression('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).{20}$/', $phone->sip_password);
        Queue::assertPushed(SyncVoiceServer::class);
    }

    public function test_desk_phone_page_is_locked_on_free(): void
    {

        $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => time()])
            ->get('/admin/desk-phones')->assertRedirect('/dashboard');
    }

    public function test_a_phone_can_be_locked_to_where_it_signs_in_from(): void
    {
        $this->usePlan(Plan::Pro);
        $dir = storage_path('framework/testing/fs-'.bin2hex(random_bytes(4)));
        config(['voip.freeswitch.conf_dir' => $dir, 'voip.freeswitch.esl_password' => null, 'voip.freeswitch.webhook_secret' => str_repeat('a1', 10)]);
        $admin = $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => time()]);

        foreach (['10.0.0.0/4', 'office', '1.1.1.1, 2.2.2.2, 3.3.3.3, 4.4.4.4, 5.5.5.5, 6.6.6.6'] as $bad) {
            $admin->patch("/admin/desk-phones/{$this->phone->id}/lock", ['allowed_ips' => $bad])->assertSessionHasErrors('allowed_ips', null, 'lock');
        }
        $admin->patch("/admin/desk-phones/{$this->phone->id}/lock", ['allowed_ips' => '203.0.113.7, 198.51.100.0/24 2001:db8::1', 'filter_lan' => '1'])
            ->assertSessionHas('status', 'Reception can now only sign in from 203.0.113.7, 198.51.100.0/24, 2001:db8::1.');
        $this->assertSame('203.0.113.7/32,198.51.100.0/24,2001:db8::1/128', $this->phone->fresh()->allowed_ips);

        app(\App\Voice\VoiceServer::class)->sync();
        $users = file_get_contents($dir.'/directory/users.xml');
        $this->assertStringContainsString('<user id="u'.$this->owner->id.'-abc123"><params><param name="a1-hash" value="', $users);
        $this->assertStringContainsString('<param name="auth-acl" value="203.0.113.7/32,198.51.100.0/24,2001:db8::1/128"/>', $users);
        $this->assertSame(1, substr_count($users, 'auth-acl')); // the softphone isn't locked

        // Not filtering LAN addresses (the default): local networks may sign in too.
        $admin->patch("/admin/desk-phones/{$this->phone->id}/lock", ['allowed_ips' => '203.0.113.7'])
            ->assertSessionHas('status', 'Reception can now only sign in from 203.0.113.7, or from the local network.');
        app(\App\Voice\VoiceServer::class)->sync();
        $this->assertStringContainsString('<param name="auth-acl" value="203.0.113.7/32,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,fc00::/7"/>', file_get_contents($dir.'/directory/users.xml'));

        $admin->patch("/admin/desk-phones/{$this->phone->id}/lock", ['allowed_ips' => ''])->assertSessionHas('status', 'Reception can sign in from anywhere.');
        $this->assertNull($this->phone->fresh()->allowed_ips);
        \Illuminate\Support\Facades\File::deleteDirectory($dir);
    }

    public function test_a_phone_can_be_locked_to_a_host_name_that_follows_its_address(): void
    {
        $this->usePlan(Plan::Pro);
        $dir = storage_path('framework/testing/fs-'.bin2hex(random_bytes(4)));
        config(['voip.freeswitch.conf_dir' => $dir, 'voip.freeswitch.esl_password' => null, 'voip.freeswitch.webhook_secret' => str_repeat('a1', 10)]);
        $dns = new class extends \App\Voice\PhoneLocks
        {
            public array $records = ['office.example.com' => ['203.0.113.7/32']];

            protected function lookup(string $host): array
            {
                return $this->records[$host] ?? [];
            }
        };
        $this->app->instance(\App\Voice\PhoneLocks::class, $dns);
        $users = function () use ($dir) {
            app(\App\Voice\VoiceServer::class)->sync();

            return file_get_contents($dir.'/directory/users.xml');
        };

        $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => time()])
            ->patch("/admin/desk-phones/{$this->phone->id}/lock", ['allowed_ips' => 'Office.Example.com.'])
            ->assertSessionHas('status', 'Reception can now only sign in from office.example.com, or from the local network.');
        $this->assertStringContainsString('<param name="auth-acl" value="203.0.113.7/32,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,fc00::/7"/>', $users());

        // The office's address changes: the refresh notices and updates the voice server.
        Queue::fake();
        $dns->records['office.example.com'] = ['198.51.100.9/32'];
        $this->artisan('phones:refresh-locks')->assertSuccessful();
        Queue::assertPushed(SyncVoiceServer::class);
        $this->assertStringContainsString('<param name="auth-acl" value="198.51.100.9/32,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,fc00::/7"/>', $users());

        // Nothing changed: nothing to do.
        Queue::fake();
        $this->artisan('phones:refresh-locks')->assertSuccessful();
        Queue::assertNotPushed(SyncVoiceServer::class);

        // A failed lookup keeps the last address; a name that never resolved lets nothing in.
        $dns->records = [];
        $this->assertStringContainsString('<param name="auth-acl" value="198.51.100.9/32,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,fc00::/7"/>', $users());
        $this->phone->update(['allowed_ips' => 'nowhere.example.com']);
        $this->assertStringContainsString('<param name="auth-acl" value="0.0.0.0/32,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,fc00::/7"/>', $users());
        \Illuminate\Support\Facades\File::deleteDirectory($dir);
    }
}
