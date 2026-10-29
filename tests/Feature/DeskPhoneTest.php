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
}
