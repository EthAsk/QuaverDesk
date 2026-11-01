<?php

namespace Tests\Feature;

use App\Domain\Licensing\Plan;
use App\Models\DeskPhone;
use App\Models\Mailbox;
use App\Models\PhoneNumber;
use App\Models\SipTrunk;
use App\Models\User;
use App\Voice\FreeSwitch\FreeSwitchServer;
use App\Voice\VoiceServer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

/** Desk-phone-only users, colleagues-only calling, voicemail on/off and phone online status. */
class PhoneOnlyAndCallLimitsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->usePlan(Plan::Pro, seats: 3);
        $this->owner = User::factory()->owner()->withTwoFactor()->create();
        $this->acknowledgeTerms($this->owner);
        config(['voip.caller_id' => '+442079460000']);
        SipTrunk::create([
            'name' => 'HQ', 'provider' => 'other', 'inbound_ips' => [['ip' => '203.0.113.10', 'prefix' => 32]],
            'origination_uris' => ['sip:sbc.example.net'], 'status' => 'active', 'default_outbound' => true,
        ]);
    }

    private function admin(): static
    {
        return $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => time()]);
    }

    private function inboundTo(User $user, int $n): string
    {
        PhoneNumber::query()->updateOrCreate(['e164' => '+442079460000'], ['destination' => "user:{$user->id}"]);

        return $this->voicePost('/webhooks/voice/inbound', [
            'session_id' => self::callId($n), 'Caller-Caller-ID-Number' => '+447700900999', 'Caller-Destination-Number' => '+442079460000',
        ])->getContent();
    }

    private function dial(string $from, string $to, int $n): string
    {
        return $this->voicePost('/webhooks/voice/outgoing', ['session_id' => self::callId($n), 'from' => $from, 'Caller-Destination-Number' => $to])->getContent();
    }

    public function test_an_admin_adds_a_desk_phone_only_user_who_takes_a_seat_and_never_signs_in(): void
    {
        $this->admin()->post('/admin/users', ['role' => 'phone_only', 'name' => 'Lobby', 'email' => 'lobby@example.test'])
            ->assertSessionHasNoErrors()->assertSessionHas('status', fn ($s) => str_contains($s, 'Lobby was added for desk phones only'));
        Mail::assertNothingQueued(); // no invite

        $lobby = User::query()->firstWhere('email', 'lobby@example.test');
        $this->assertTrue($lobby->isPhoneOnly());
        $this->assertFalse($lobby->canSignIn());
        $this->assertSame(User::ROLE_USER, $lobby->role);
        $this->get('/admin/users')->assertSee('2 of 3 users')->assertSee('Desk phones only');

        $this->post('/logout');
        $this->post('/login', ['email' => 'lobby@example.test', 'password' => 'password-1234'])->assertSessionHasErrors('email');
        $this->actingAs($lobby)->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
        $this->actingAs($this->owner)->post("/admin/users/{$lobby->id}/invite")->assertNotFound();

        // Only their desk phone rings: no softphone.
        $lobby->deskPhones()->create(['label' => 'Lobby', 'sip_username' => "u{$lobby->id}-abc123", 'sip_password' => 'Secret123456Abc']);
        $xml = $this->inboundTo($lobby, 1);
        $this->assertStringContainsString("user/u{$lobby->id}-abc123@voice.test", $xml);
        $this->assertStringNotContainsString("user/user-{$lobby->id}@", $xml);

        // And they can't be added when the plan is full.
        User::factory()->create(); // 3 of 3
        $this->admin()->post('/admin/users', ['role' => 'phone_only', 'name' => 'Hall', 'email' => 'hall@example.test'])->assertSessionHasErrors('email');
    }

    public function test_the_voice_server_has_no_softphone_for_them(): void
    {
        $dir = storage_path('framework/testing/fs-'.bin2hex(random_bytes(4)));
        config(['voip.freeswitch.conf_dir' => $dir, 'voip.freeswitch.esl_password' => null, 'voip.freeswitch.webhook_secret' => str_repeat('a1', 10)]);
        $lobby = User::factory()->create(['phone_only' => true]);
        $lobby->deskPhones()->create(['label' => 'Lobby', 'sip_username' => "u{$lobby->id}-abc123", 'sip_password' => 'Secret123456Abc']);

        app(VoiceServer::class)->sync();
        $users = file_get_contents($dir.'/directory/users.xml');
        $this->assertStringContainsString('<user id="u'.$lobby->id.'-abc123">', $users);
        $this->assertStringNotContainsString('<user id="user-'.$lobby->id.'">', $users);
        $this->assertStringContainsString('<user id="user-'.$this->owner->id.'">', $users);
        File::deleteDirectory($dir);
    }

    public function test_switching_between_full_desk_phones_only_and_forward_only(): void
    {
        $sam = User::factory()->create(['name' => 'Sam']);
        $sam->deskPhones()->create(['label' => 'Desk', 'sip_username' => "u{$sam->id}-abc123", 'sip_password' => 'Secret123456Abc']);
        DB::table('sessions')->insert(['id' => 'sam-session', 'user_id' => $sam->id, 'payload' => '', 'last_activity' => time()]);
        $secret = $sam->sipSecret();

        $this->admin()->patch("/admin/users/{$sam->id}/type", ['type' => 'phone_only'])
            ->assertSessionHas('status', 'Sam is now desk phones only: signed out, with no softphone. Calls ring their desk phones.');
        $sam->refresh();
        $this->assertTrue($sam->isPhoneOnly());
        $this->assertSame(1, $sam->deskPhones()->count()); // kept
        $this->assertSame(0, DB::table('sessions')->where('user_id', $sam->id)->count());
        $this->assertNotSame($secret, $sam->sipSecret());
        $this->patch("/admin/users/{$sam->id}/type", ['type' => 'phone_only'])->assertNotFound();

        // A sign-in again: no extra seat needed, and they're emailed a link.
        $this->patch("/admin/users/{$sam->id}/type", ['type' => 'user'])->assertSessionHas('invited');
        $this->assertTrue($sam->fresh()->canSignIn());
        Mail::assertQueued(\App\Mail\UserInvited::class);

        // From forward-only, desk phones only takes a seat: refused when the plan is full.
        $this->patch("/admin/users/{$sam->id}/type", ['type' => 'forward_only']);
        $this->assertTrue($sam->fresh()->isForwardOnly());
        User::factory()->count(2)->create(); // 3 of 3
        $this->patch("/admin/users/{$sam->id}/type", ['type' => 'phone_only'])->assertSessionHasErrors('type', null, 'type');
        $this->assertTrue($sam->fresh()->isForwardOnly());
        User::query()->latest('id')->first()->delete();
        $this->patch("/admin/users/{$sam->id}/type", ['type' => 'phone_only'])->assertSessionHasNoErrors();
        $this->assertTrue($sam->fresh()->isPhoneOnly());
        $this->assertFalse($sam->fresh()->isForwardOnly());
    }

    public function test_colleagues_only_can_call_extensions_and_999_but_no_outside_numbers(): void
    {
        $sam = User::factory()->create(['name' => 'Sam']);
        $sam->deskPhones()->create(['label' => 'Desk', 'sip_username' => "u{$sam->id}-abc123", 'sip_password' => 'Secret123456Abc']);
        $this->assertStringContainsString('/+447700900123"', $this->dial("u{$sam->id}-abc123", '07700900123', 1));

        $this->admin()->patch("/admin/users/{$sam->id}/calling", ['internal_only' => '1'])
            ->assertSessionHas('status', 'Sam can now only call colleagues (and 999).');
        $this->assertTrue($sam->fresh()->isInternalOnly());

        // Desk phone and softphone alike.
        foreach (["u{$sam->id}-abc123", "user-{$sam->id}"] as $n => $from) {
            $xml = $this->dial($from, '07700900123', 10 + $n);
            $this->assertStringContainsString('This phone can only call colleagues.', $xml);
            $this->assertStringNotContainsString('bridge', $xml);
        }
        $this->assertStringContainsString("user/user-{$this->owner->id}@voice.test", $this->dial("u{$sam->id}-abc123", "user-{$this->owner->id}", 20));
        $xml = $this->dial("u{$sam->id}-abc123", '999', 21);
        $this->assertStringNotContainsString('This phone can only call colleagues.', $xml);
        $this->assertStringContainsString('bridge', $xml);

        // Set when adding someone too.
        $this->admin()->post('/admin/users', ['role' => 'phone_only', 'name' => 'Lobby', 'email' => 'lobby@example.test', 'internal_only' => '1']);
        $this->assertTrue(User::query()->firstWhere('email', 'lobby@example.test')->isInternalOnly());

        $this->patch("/admin/users/{$sam->id}/calling", ['internal_only' => '0'])->assertSessionHasNoErrors();
        $this->assertFalse($sam->fresh()->isInternalOnly());
    }

    public function test_voicemail_can_be_turned_off_and_on_per_user(): void
    {
        $sam = User::factory()->create(['name' => 'Sam']);
        $box = Mailbox::query()->create(['name' => 'Sam', 'greeting' => 'Leave a message.']);
        $sam->forceFill(['mailbox_id' => $box->id])->save();
        $this->assertStringContainsString("next=voicemail%3A{$box->id}", $this->inboundTo($sam, 1));

        $this->admin()->get('/admin/users')->assertSee('Voicemail on');
        $this->patch("/admin/users/{$sam->id}/voicemail", ['voicemail' => '0'])
            ->assertSessionHas('status', "Sam's voicemail is off: calls they don't answer will end.");
        $sam->refresh();
        $this->assertFalse($sam->hasVoicemail());
        $this->assertSame($box->id, $sam->mailbox_id); // the box and its messages stay

        $xml = $this->inboundTo($sam, 2);
        $this->assertStringNotContainsString('voicemail%3A', $xml);
        $this->assertStringContainsString('next=hangup', $xml);

        $this->patch("/admin/users/{$sam->id}/voicemail", ['voicemail' => '1'])->assertSessionHas('status', "Sam's voicemail is on.");
        $this->assertStringContainsString("next=voicemail%3A{$box->id}", $this->inboundTo($sam->fresh(), 3));
    }

    public function test_phones_and_apps_shows_which_phones_are_online(): void
    {
        $phone = DeskPhone::create(['user_id' => $this->owner->id, 'label' => 'Reception', 'sip_username' => "u{$this->owner->id}-abc123", 'sip_password' => 'Secret123456Abc']);
        DeskPhone::create(['user_id' => $this->owner->id, 'label' => 'Kitchen', 'sip_username' => "u{$this->owner->id}-def456", 'sip_password' => 'Secret123456Abc']);
        $reg = ["u{$this->owner->id}-abc123" => ['ip' => '192.0.2.50', 'agent' => 'Yealink SIP-T43U 108.86.0.40', 'transport' => 'TLS']];
        $this->app->instance(VoiceServer::class, new class(config('voip.freeswitch'), $reg) extends FreeSwitchServer
        {
            public bool $down = false;

            public function __construct(array $config, private array $reg)
            {
                parent::__construct($config);
            }

            public function registrations(): array
            {
                return $this->down ? throw new RuntimeException('down') : $this->reg;
            }
        });

        $this->admin()->get('/admin/desk-phones')->assertOk()->assertSeeInOrder(['Kitchen', 'Offline', 'Reception', 'Online', '192.0.2.50', 'Yealink']);

        app(VoiceServer::class)->down = true;
        $this->admin()->get('/admin/desk-phones')->assertOk()->assertSee('Unknown')->assertDontSee('Offline');
        $this->assertSame('Reception', $phone->label);
    }

    public function test_999_still_goes_out_when_outgoing_calls_are_off(): void
    {
        $hq = SipTrunk::query()->firstOrFail();
        $spare = SipTrunk::create([
            'name' => 'Spare', 'provider' => 'other', 'inbound_ips' => [['ip' => '203.0.113.20', 'prefix' => 32]],
            'origination_uris' => ['sip:spare.example.net'], 'status' => 'active',
        ]);
        PhoneNumber::create(['e164' => '+441709000001', 'destination' => "user:{$this->owner->id}", 'sip_trunk_id' => $spare->id]);
        config(['voip.caller_id' => null]); // no caller ID set either

        $this->admin()->delete('/admin/trunks/default')
            ->assertSessionHas('status', 'No trunk is set for outgoing calls, so calls to phone numbers are off. 999 and 112 calls still go out on Spare.');
        $this->assertFalse($hq->fresh()->default_outbound);
        $this->get('/admin/trunks')->assertSee('999 and 112 calls still go out on');

        // Ordinary numbers are refused, but 999 and 112 go out on the trunk with the company's numbers, showing one of them.
        $this->assertStringNotContainsString('bridge', $this->dial("user-{$this->owner->id}", '07700900123', 1));
        foreach (['999', '112'] as $n => $digits) {
            $xml = $this->dial("user-{$this->owner->id}", $digits, 2 + $n);
            $this->assertStringContainsString('bridge', $xml);
            $this->assertStringContainsString($spare->dialTrunk(), $xml);
            $this->assertStringContainsString('441709000001', $xml);
            $this->assertStringNotContainsString('cannot make emergency calls', $xml);
            $this->assertDatabaseHas('calls', ['carrier_sid' => self::callId(2 + $n), 'sip_trunk_id' => $spare->id, 'decision' => 'emergency']);
        }

        // With no active trunk at all, the admin is told 999 can't be made.
        SipTrunk::query()->update(['status' => 'disabled']);
        $this->get('/admin/trunks')->assertSee('No trunk is active, so 999 and 112 calls can');
        $this->assertStringContainsString('cannot make emergency calls', $this->dial("user-{$this->owner->id}", '999', 9));
    }
}
