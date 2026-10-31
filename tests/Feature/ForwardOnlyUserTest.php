<?php

namespace Tests\Feature;

use App\Domain\Licensing\Plan;
use App\Models\Call;
use App\Models\DeskPhone;
use App\Models\Mailbox;
use App\Models\PhoneNumber;
use App\Models\SipTrunk;
use App\Models\User;
use App\Voice\FreeSwitch\FreeSwitchServer;
use App\Voice\VoiceServer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** People whose calls go to their mobile or their emailed voicemail: no sign-in, no phones, free. */
class ForwardOnlyUserTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private SipTrunk $trunk;
    private FreeSwitchServer $voice;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->usePlan(Plan::Plus, seats: 2);
        $this->owner = User::factory()->owner()->withTwoFactor()->create();
        $this->acknowledgeTerms($this->owner);
        config(['voip.caller_id' => '+442079460000']);
        $this->trunk = SipTrunk::create([
            'name' => 'HQ', 'provider' => 'other', 'inbound_ips' => [['ip' => '203.0.113.10', 'prefix' => 32]],
            'origination_uris' => ['sip:sbc.example.net'], 'status' => 'active',
        ]);
        $this->trunk->forceFill(['default_outbound' => true])->save();

        $this->voice = new class(config('voip.freeswitch')) extends FreeSwitchServer
        {
            public array $calls = [];

            public function callNumber(string $e164, string $trunk, string $callerId, string $callId, string $url): void
            {
                $this->calls[] = compact('e164', 'callId', 'url');
            }
        };
        $this->app->instance(VoiceServer::class, $this->voice);
    }

    private function inboundTo(User $user, int $n): string
    {
        PhoneNumber::query()->updateOrCreate(['e164' => '+442079460000'], ['destination' => "user:{$user->id}", 'sip_trunk_id' => $this->trunk->id]);

        return $this->voicePost('/webhooks/voice/inbound', [
            'session_id' => self::callId($n), 'Caller-Caller-ID-Number' => '+447700900999', 'Caller-Destination-Number' => '+442079460000',
        ])->getContent();
    }

    public function test_an_admin_adds_one_free_even_when_the_plan_is_full(): void
    {
        User::factory()->create(); // 2 of 2 users
        $this->actingAs($this->owner)->get('/admin/users')->assertSee('2 of 2 users')->assertSee('Add a forward-only contact');

        $this->post('/admin/users', ['role' => 'forward_only', 'name' => 'Pat Field', 'email' => 'Pat@Example.test', 'mobile' => '07700 900123'])
            ->assertSessionHasNoErrors()->assertSessionHas('status', fn ($s) => str_contains($s, "Calling Pat Field's mobile now"));

        $pat = User::query()->firstWhere('email', 'pat@example.test');
        $this->assertTrue($pat->isForwardOnly());
        $this->assertSame(['+447700900123', null, User::ROLE_USER], [$pat->mobile, $pat->mobile_verified_at, $pat->role]);
        $this->assertNotNull($pat->extension);
        Mail::assertNothingQueued(); // no invite: there's nothing to sign in to

        // Their own box, emailed to them with the audio attached.
        $box = Mailbox::query()->findOrFail($pat->mailbox_id);
        $this->assertSame([[$pat->id], true], [$box->members()->pluck('users.id')->all(), $box->attach_audio]);

        // We call the mobile so they can press 1.
        [$placed] = $this->voice->calls;
        $this->assertSame('+447700900123', $placed['e164']);
        $this->voicePost('/webhooks/voice/confirm-mobile?asked=1', ['session_id' => $placed['callId'], 'digits' => '1'])->assertSee('Your mobile is confirmed');
        $this->assertTrue($pat->fresh()->followsMe());

        $this->get('/admin/users')->assertSee('2 of 2 users')->assertSee('Forward only');
    }

    public function test_a_confirmed_mobile_is_the_only_thing_rung(): void
    {
        $pat = User::factory()->forwardOnly('+447700900123')->create();

        $xml = $this->inboundTo($pat, 1);

        $this->assertSame(1, Call::query()->where('leg', Call::LEG_FOLLOW_ME)->count());
        $this->assertStringNotContainsString("user/user-{$pat->id}@", $xml);
    }

    public function test_without_a_confirmed_mobile_calls_go_straight_to_voicemail(): void
    {
        $this->actingAs($this->owner)->post('/admin/users', ['role' => 'forward_only', 'name' => 'Pat Field', 'email' => 'pat@example.test']);
        $pat = User::query()->firstWhere('email', 'pat@example.test');

        $xml = $this->inboundTo($pat, 2);
        $this->assertStringNotContainsString('bridge', $xml);
        $this->assertMatchesRegularExpression('#<record [^>]*action="[^"]+/webhooks/voice/voicemail-saved\?box=voicemail%3A'.$pat->mailbox_id.'"#', $xml);

        // An unconfirmed number doesn't ring either.
        $this->patch("/admin/users/{$pat->id}/mobile", ['mobile' => '07700900123'])->assertSessionHasNoErrors();
        $this->assertStringNotContainsString('bridge', $this->inboundTo($pat->fresh(), 3));
        $this->patch("/admin/users/{$pat->id}/mobile", ['mobile' => '020 7946 0000'])->assertSessionHasErrors('mobile', null, 'mobile');
    }

    public function test_they_can_never_sign_in_or_have_phones(): void
    {
        $pat = User::factory()->forwardOnly()->create(['email' => 'pat@example.test']);

        $this->post('/login', ['email' => 'pat@example.test', 'password' => 'password-1234'])->assertSessionHasErrors('email');
        $this->assertGuest();

        // However they got a session, it ends.
        $this->actingAs($pat)->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();

        $this->actingAs($this->owner)->post("/admin/users/{$pat->id}/invite")->assertNotFound();
        $this->withSession(['auth.password_confirmed_at' => time()])->post('/admin/desk-phones', ['user_id' => $pat->id, 'name' => 'Desk'])->assertSessionHasErrors('user_id');
        $this->assertSame(0, DeskPhone::query()->count());
    }

    public function test_an_admin_switches_someone_to_forward_only_and_back(): void
    {
        $sam = User::factory()->create(['name' => 'Sam']);
        $sam->forceFill(['mobile' => '+447700900123', 'mobile_verified_at' => now(), 'follow_me' => User::FOLLOW_ME_AFTER])->save();
        $sam->deskPhones()->create(['label' => 'Desk', 'sip_username' => "u{$sam->id}-abc123", 'sip_password' => 'Secret123456Abc']);
        $group = \App\Models\RingGroup::query()->create(['name' => 'Sales', 'strategy' => 'simultaneous', 'ring_seconds' => 20]);
        $group->members()->attach($sam, ['position' => 1]);
        \Illuminate\Support\Facades\DB::table('sessions')->insert(['id' => 'sam-session', 'user_id' => $sam->id, 'payload' => '', 'last_activity' => time()]);
        $secret = $sam->sipSecret();

        $this->actingAs($this->owner)->patch("/admin/users/{$sam->id}/type", ['type' => 'forward_only'])
            ->assertSessionHas('status', 'Sam is now a forward-only contact: signed out, with their phones removed. Their calls now ring their mobile.');

        $sam->refresh();
        $this->assertTrue($sam->isForwardOnly());
        $this->assertTrue($sam->followsMe());
        $this->assertSame(0, $sam->deskPhones()->count());
        $this->assertSame(0, $group->members()->count());
        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('sessions')->where('user_id', $sam->id)->count());
        $this->assertNotSame($secret, $sam->sipSecret());
        $this->assertSame([$sam->id], Mailbox::query()->findOrFail($sam->mailbox_id)->members()->pluck('users.id')->all());
        $this->get('/admin/users')->assertSee('1 of 2 users')->assertSee('Give a sign-in');

        // Back again: it takes a seat, and they're emailed a link to set a password.
        $this->patch("/admin/users/{$sam->id}/type", ['type' => 'admin'])->assertSessionHas('invited');
        $this->assertFalse($sam->fresh()->isForwardOnly());
        $this->assertTrue($sam->fresh()->isAdmin());
        Mail::assertQueued(\App\Mail\UserInvited::class);

        // Not when the plan is full.
        $this->patch("/admin/users/{$sam->id}/type", ['type' => 'forward_only']);
        User::factory()->create();
        $this->patch("/admin/users/{$sam->id}/type", ['type' => 'user'])->assertSessionHasErrors('type', null, 'type');
        $this->assertTrue($sam->fresh()->isForwardOnly());

        // Never the owner or yourself.
        $this->patch("/admin/users/{$this->owner->id}/type", ['type' => 'forward_only'])->assertForbidden();
    }

    public function test_removing_someone_deletes_the_voicemail_nobody_else_gets(): void
    {
        $this->actingAs($this->owner)->post('/admin/users', ['role' => 'forward_only', 'name' => 'Pat', 'email' => 'pat@example.test']);
        $pat = User::query()->firstWhere('email', 'pat@example.test');
        $own = Mailbox::query()->findOrFail($pat->mailbox_id);
        $sales = Mailbox::query()->create(['name' => 'Sales', 'greeting' => 'Hi']);
        $sales->members()->attach([$pat->id, $this->owner->id]);
        $store = app(\App\Support\RecordingStore::class);
        $message = fn (Mailbox $box) => tap(\App\Models\Recording::query()->create(['kind' => 'voicemail', 'mailbox_id' => $box->id, 'carrier_sid' => bin2hex(random_bytes(8))]),
            fn ($r) => $store->put($r, 'audio'));
        $mine = $message($own);
        $shared = $message($sales);
        $path = $mine->fresh()->path;

        $this->delete("/admin/users/{$pat->id}")
            ->assertSessionHas('status', 'Pat was removed. Their voicemail box (Pat) and its messages were deleted too.');

        $this->assertNull(Mailbox::query()->find($own->id));
        $this->assertNull(\App\Models\Recording::query()->find($mine->id));
        \Illuminate\Support\Facades\Storage::disk('local')->assertMissing($path);
        $this->assertNotNull(Mailbox::query()->find($sales->id)); // still reaches the owner
        $this->assertNotNull(\App\Models\Recording::query()->find($shared->id));
    }
}
