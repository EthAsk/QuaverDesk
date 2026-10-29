<?php

namespace Tests\Feature;

use App\Models\Call;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VoiceWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_webhook_without_the_secret_is_rejected(): void
    {
        $this->post('/webhooks/voice/outgoing', ['from' => 'user-1', 'Caller-Destination-Number' => 'user-2'])->assertForbidden();

        $this->call('POST', '/webhooks/voice/outgoing', [], [], [], ['PHP_AUTH_USER' => 'freeswitch', 'PHP_AUTH_PW' => 'wrong-secret-0000000'])
            ->assertForbidden();
    }

    public function test_internal_call_dials_the_colleague(): void
    {
        $a = User::factory()->owner()->create();
        $b = User::factory()->create();
        $this->acknowledgeTerms($a);

        $response = $this->voicePost('/webhooks/voice/outgoing', [
            'session_id' => self::callId(111), 'from' => 'user-'.$a->id, 'Caller-Destination-Number' => 'user-'.$b->id,
        ]);

        $response->assertOk()->assertHeader('Content-Type', 'text/xml; charset=UTF-8');
        $this->assertStringContainsString("<execute application=\"bridge\" data=\"{originate_timeout=25}[execute_on_answer_qd='bridge_export nolocal:qd_by=user-{$b->id}']user/user-{$b->id}@voice.test\"", $response->getContent());
        $this->assertStringContainsString('<getVariable name="originate_disposition"/>', $response->getContent());
        $this->assertSame('internal', Call::firstWhere('carrier_sid', self::callId(111))->direction);
    }

    public function test_dialling_an_extension_rings_that_person(): void
    {
        $a = User::factory()->owner()->create();
        $b = User::factory()->create();
        $b->forceFill(['extension' => '2468'])->save();
        $this->acknowledgeTerms($a);

        $response = $this->voicePost('/webhooks/voice/outgoing', [
            'session_id' => self::callId(112), 'from' => 'user-'.$a->id, 'Caller-Destination-Number' => '2468',
        ]);

        $this->assertStringContainsString("']user/user-{$b->id}@voice.test\"", $response->getContent());
        $this->assertSame('user-'.$b->id, Call::firstWhere('carrier_sid', self::callId(112))->to);
    }

    public function test_free_plan_cannot_dial_phone_numbers(): void
    {
        $a = User::factory()->owner()->create();
        $this->acknowledgeTerms($a);

        $xml = $this->voicePost('/webhooks/voice/outgoing', [
            'session_id' => self::callId(222), 'from' => 'user-'.$a->id, 'Caller-Destination-Number' => '07700900123',
        ])->getContent();

        $this->assertStringContainsString('<speak', $xml);
        $this->assertStringNotContainsString('bridge', $xml);
    }

    public function test_emergency_before_setup_tells_caller_to_use_another_phone(): void
    {
        $a = User::factory()->owner()->create();

        $xml = $this->voicePost('/webhooks/voice/outgoing', [
            'session_id' => self::callId(333), 'from' => 'user-'.$a->id, 'Caller-Destination-Number' => '999',
        ])->getContent();

        $this->assertStringContainsString('dial 999 from a mobile or landline', $xml);
    }

    public function test_dial_outcome_and_call_end_update_the_call(): void
    {
        $a = User::factory()->owner()->create();
        Call::create(['carrier_sid' => self::callId(444), 'user_id' => $a->id, 'direction' => 'internal', 'status' => 'initiated']);

        $xml = $this->voicePost('/webhooks/voice/after-dial?next=hangup', [
            'session_id' => self::callId(444), 'originate_disposition' => 'NO_ANSWER',
        ])->getContent();
        $this->assertStringContainsString('<hangup/>', $xml);
        $this->assertSame('no-answer', Call::firstWhere('carrier_sid', self::callId(444))->status);

        $this->voicePost('/webhooks/voice/after-dial?next=hangup', ['session_id' => self::callId(444), 'exiting' => 'true'])
            ->assertNoContent();
        $this->assertNotNull(Call::firstWhere('carrier_sid', self::callId(444))->duration_seconds);
    }

    public function test_softphone_gets_its_sign_in_details(): void
    {
        $this->acknowledgeTerms(User::factory()->owner()->create());
        $a = User::factory()->create();

        $this->actingAs($a)->postJson('/softphone/token')
            ->assertOk()
            ->assertJson(['websocket_url' => 'wss://voice.test:7443', 'uri' => "sip:user-{$a->id}@voice.test", 'username' => "user-{$a->id}"])
            ->assertJsonStructure(['password']);
        $this->assertNotEmpty($a->fresh()->sip_secret);
    }
}
