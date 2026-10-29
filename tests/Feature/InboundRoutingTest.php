<?php

namespace Tests\Feature;

use App\Domain\Licensing\Plan;
use App\Models\IvrMenu;
use App\Models\PhoneNumber;
use App\Models\RingGroup;
use App\Models\SipTrunk;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InboundRoutingTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->owner()->withTwoFactor()->create();
        $this->acknowledgeTerms($this->owner);
    }

    private function inbound(int $call, string $to = '+442079460000', array $extra = []): string
    {
        return $this->voicePost('/webhooks/voice/inbound', [
            'session_id' => self::callId($call), 'Caller-Caller-ID-Number' => '+447700900123', 'Caller-Destination-Number' => $to,
        ] + $extra)->getContent();
    }

    public function test_free_plan_numbers_ring_too(): void
    {
        PhoneNumber::create(['e164' => '+442079460000', 'destination' => "user:{$this->owner->id}"]);

        $this->assertStringContainsString("']user/user-{$this->owner->id}@voice.test\"", $this->inbound(1));
    }

    public function test_call_to_number_rings_the_chosen_user(): void
    {
        $this->usePlan(Plan::Plus);
        PhoneNumber::create(['e164' => '+442079460000', 'destination' => "user:{$this->owner->id}"]);

        $xml = $this->inbound(2);

        $this->assertStringContainsString("']user/user-{$this->owner->id}@voice.test\"", $xml);
        $this->assertDatabaseHas('calls', ['carrier_sid' => self::callId(2), 'direction' => 'inbound', 'to' => '+442079460000']);
    }

    public function test_numbers_in_national_format_or_the_to_header_are_understood(): void
    {
        $this->usePlan(Plan::Plus);
        PhoneNumber::create(['e164' => '+442079460000', 'destination' => "user:{$this->owner->id}"]);

        $this->assertStringContainsString('bridge', $this->inbound(3, '02079460000'));
        // Registration trunks: the request line carries the account name, the To header the number.
        $this->assertStringContainsString('bridge', $this->inbound(4, 'acct12345', ['to_user' => '442079460000']));
    }

    public function test_ivr_menu_then_choice(): void
    {
        $this->usePlan(Plan::Plus);
        $menu = IvrMenu::create(['name' => 'Main', 'greeting' => 'Press 1 for sales.', 'options' => ['1' => "user:{$this->owner->id}"], 'timeout_seconds' => 5]);
        PhoneNumber::create(['e164' => '+442079460000', 'destination' => "ivr:{$menu->id}"]);

        $xml = $this->inbound(5);
        $this->assertMatchesRegularExpression('#<speak engine="flite" voice="slt" name="digits" input-timeout="5000" action="[^"]+ivr-choice[^"]*">Press 1 for sales\.<bind>~\\\\d\{1\}</bind></speak>#', $xml);

        $xml = $this->voicePost("/webhooks/voice/ivr-choice?ivr=ivr:{$menu->id}&hop=0", ['session_id' => self::callId(5), 'digits' => '1'])->getContent();
        $this->assertStringContainsString("']user/user-{$this->owner->id}@voice.test\"", $xml);
    }

    public function test_ivr_with_no_key_press_replays_quietly(): void
    {
        $this->usePlan(Plan::Plus);
        $menu = IvrMenu::create(['name' => 'Main', 'greeting' => 'Press 1 for sales.', 'options' => ['1' => "user:{$this->owner->id}"], 'timeout_seconds' => 5]);

        $xml = $this->voicePost("/webhooks/voice/ivr-choice?ivr=ivr:{$menu->id}&hop=0", ['session_id' => self::callId(6)])->getContent();

        $this->assertStringNotContainsString('not a valid option', $xml);
        $this->assertMatchesRegularExpression('#<continue action="[^"]+/webhooks/voice/route\?[^"]*to=ivr%3A'.$menu->id.'%3A1#', $xml);
    }

    public function test_in_order_ring_group_moves_to_next_member(): void
    {
        $this->usePlan(Plan::Plus);
        $second = User::factory()->create();
        $group = RingGroup::create(['name' => 'Sales', 'strategy' => 'in_order', 'ring_seconds' => 15]);
        $group->members()->sync([$this->owner->id => ['position' => 0], $second->id => ['position' => 1]]);

        $xml = $this->voicePost("/webhooks/voice/after-dial?hop=0&next=ring_group:{$group->id}:1", [
            'session_id' => self::callId(7), 'originate_disposition' => 'NO_ANSWER',
        ])->getContent();

        $this->assertStringContainsString("{originate_timeout=15}[execute_on_answer_qd='bridge_export nolocal:qd_by=user-{$second->id}']user/user-{$second->id}@voice.test", $xml);
    }

    public function test_outgoing_calls_use_the_default_trunk(): void
    {
        $this->usePlan(Plan::Plus);
        SipTrunk::create([
            'name' => 'HQ', 'provider' => 'other', 'inbound_ips' => [['ip' => '203.0.113.10', 'prefix' => 32]],
            'origination_uris' => ['sip:sbc1.example.net', 'sip:sbc2.example.net'], 'status' => 'active', 'default_outbound' => true,
        ]);
        $trunk = SipTrunk::first();

        $xml = $this->voicePost('/webhooks/voice/outgoing', [
            'session_id' => self::callId(8), 'from' => "user-{$this->owner->id}", 'Caller-Destination-Number' => '07700900123',
        ])->getContent();

        $this->assertStringContainsString(
            "origination_caller_id_number=+442079460000,origination_caller_id_name=+442079460000}sofia/gateway/{$trunk->gatewayNames()[0]}/+447700900123|sofia/gateway/{$trunk->gatewayNames()[1]}/+447700900123",
            $xml,
        );
    }

    public function test_without_a_trunk_phone_numbers_cannot_be_called(): void
    {
        $this->usePlan(Plan::Plus);

        $xml = $this->voicePost('/webhooks/voice/outgoing', [
            'session_id' => self::callId(9), 'from' => "user-{$this->owner->id}", 'Caller-Destination-Number' => '07700900123',
        ])->getContent();

        $this->assertStringContainsString('no phone line for outside calls', $xml);
        $this->assertStringNotContainsString('bridge', $xml);
    }

    public function test_free_has_one_of_each_and_then_asks_to_upgrade(): void
    {
        $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => time()]);
        $this->get('/admin/ring-groups')->assertOk();
        $this->get('/admin/ring-groups/new')->assertOk();
        $this->post('/admin/ring-groups', ['name' => 'Sales', 'strategy' => 'simultaneous', 'ring_seconds' => 20, 'members' => [$this->owner->id]])
            ->assertSessionHasNoErrors();

        // The second of each sends the admin back with an upgrade prompt, on the page and on the server.
        $this->get('/admin/ring-groups/new')->assertRedirect('/admin/ring-groups')->assertSessionHas('limit', 'The Free plan includes 1 ring group. Upgrade to Plus for as many as you need.');
        $this->post('/admin/ring-groups', ['name' => 'Support', 'strategy' => 'simultaneous', 'ring_seconds' => 20])->assertRedirect('/admin/ring-groups');
        $this->assertSame(1, RingGroup::query()->count());

        IvrMenu::create(['name' => 'Main', 'greeting' => 'Hi', 'options' => []]);
        $this->get('/admin/ivr/new')->assertRedirect('/admin/ivr')->assertSessionHas('limit');
        PhoneNumber::create(['e164' => '+442079460000', 'destination' => 'hangup']);
        $this->post('/admin/numbers', ['e164' => '020 7946 0001', 'sip_trunk_id' => 1])->assertRedirect('/admin/numbers')->assertSessionHas('limit');
        SipTrunk::create(['name' => 'T', 'provider' => 'other', 'inbound_ips' => [], 'origination_uris' => ['sip:a.example'], 'status' => 'active']);
        $this->get('/admin/trunks/new')->assertRedirect('/admin/trunks')->assertSessionHas('limit');

        $this->usePlan(Plan::Plus);
        $this->get('/admin/ring-groups/new')->assertOk();
    }
}
