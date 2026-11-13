<?php

namespace Tests\Feature;

use App\Domain\Licensing\Plan;
use App\HubSpot\HubSpotSettings;
use App\Models\Call;
use App\Models\Recording;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** HubSpot CRM integration (Pro, #28). */
class HubSpotTest extends TestCase
{
    use RefreshDatabase;

    private function admin(Plan $plan = Plan::Pro): User
    {
        $this->usePlan($plan);
        $owner = User::factory()->owner()->withTwoFactor()->create();
        $this->acknowledgeTerms($owner);

        return $owner;
    }

    private function connect(array $options = []): void
    {
        app(HubSpotSettings::class)->save($options, 'pat-secret-token');
    }

    private function fakeHubSpot(?string $contact = '501'): void
    {
        Http::fake([
            'api.hubapi.com/crm/v3/objects/contacts/search' => Http::response(['results' => $contact ? [['id' => $contact]] : []]),
            'api.hubapi.com/crm/v3/objects/contacts' => Http::response(['id' => '777']),
            'api.hubapi.com/crm/v3/objects/calls' => Http::response(['id' => '9001']),
            'api.hubapi.com/crm/v3/objects/calls/*' => Http::response(['id' => '9001']),
        ]);
    }

    private function makeCall(array $extra = []): Call
    {
        return Call::create($extra + ['carrier_sid' => 'sid-'.uniqid(), 'direction' => 'inbound', 'from' => '+447700900123', 'to' => '+441142111111', 'status' => 'ringing']);
    }

    private function end(Call $call, string $status = 'completed', int $seconds = 90): void
    {
        $call->forceFill(['status' => $status, 'duration_seconds' => $seconds])->save();
    }

    public function test_only_pro_has_it(): void
    {
        $this->actingAs($this->admin(Plan::Plus))->get('/admin/hubspot')->assertRedirect('/dashboard');
        $this->get('/admin/hubspot')->assertRedirect('/dashboard');
    }

    public function test_token_is_saved_encrypted_and_never_shown_or_exported(): void
    {
        $owner = $this->admin();
        $this->actingAs($owner)->put('/admin/hubspot', ['token' => 'pat-secret-token', 'log_calls' => '1'])->assertSessionHasNoErrors();

        $this->assertStringNotContainsString('pat-secret-token', (string) Setting::query()->where('key', 'hubspot')->value('value'));
        $this->assertSame('pat-secret-token', app(HubSpotSettings::class)->token());
        $this->get('/admin/hubspot')->assertOk()->assertSee('Saved')->assertDontSee('pat-secret-token');

        // Leaving the box blank keeps the token; removing it stops logging.
        $this->put('/admin/hubspot', ['log_calls' => '1']);
        $this->assertSame('pat-secret-token', app(HubSpotSettings::class)->token());
        $this->delete('/admin/hubspot');
        $this->assertNull(app(HubSpotSettings::class)->token());
    }

    public function test_connection_test_reports_good_and_bad_tokens(): void
    {
        $owner = $this->admin();
        $this->connect();

        Http::fake(['*' => Http::sequence()->push(['results' => []])->push(['message' => 'bad'], 401)]);
        $this->actingAs($owner)->post('/admin/hubspot/test')->assertSessionHas('status', 'Connected to HubSpot.');
        $this->post('/admin/hubspot/test')->assertSessionHasErrors('test');
    }

    public function test_an_ended_inbound_call_is_logged_against_the_contact(): void
    {
        $this->admin();
        $this->connect();
        $this->fakeHubSpot();

        $call = $this->makeCall(['user_id' => User::factory()->create(['name' => 'Ann Agent'])->id]);
        $this->end($call);

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/contacts/search') && $r->hasHeader('Authorization', 'Bearer pat-secret-token')
            && json_encode($r->data()) !== false && str_contains(json_encode($r->data()), '+447700900123') && str_contains(json_encode($r->data()), '7700900123'));
        Http::assertSent(function (Request $r) {
            if (! str_ends_with($r->url(), '/objects/calls') || $r->method() !== 'POST') {
                return false;
            }
            $p = $r['properties'];

            return $p['hs_call_direction'] === 'INBOUND' && $p['hs_call_status'] === 'COMPLETED' && $p['hs_call_duration'] === '90000'
                && $p['hs_call_from_number'] === '+447700900123' && $p['hs_call_title'] === 'Incoming call (Ann Agent)'
                && $r['associations'][0]['to']['id'] === '501' && $r['associations'][0]['types'][0]['associationTypeId'] === 194;
        });
        $this->assertSame('9001', $call->refresh()->hubspot_call_id);
    }

    public function test_an_outgoing_call_matches_on_the_number_dialled_and_a_missed_call_has_no_duration(): void
    {
        $this->admin();
        $this->connect();
        $this->fakeHubSpot();

        $this->end($this->makeCall(['direction' => 'outbound', 'from' => '201', 'to' => '+447700900555']), 'no-answer', 25);

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/contacts/search') && str_contains(json_encode($r->data()), '+447700900555'));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/objects/calls') && $r['properties']['hs_call_direction'] === 'OUTBOUND'
            && $r['properties']['hs_call_status'] === 'NO_ANSWER' && ! isset($r['properties']['hs_call_duration']));
    }

    public function test_calls_with_nobody_in_hubspot_are_left_alone_unless_contacts_are_to_be_made(): void
    {
        $this->admin();
        $this->connect();
        $this->fakeHubSpot(contact: null);

        $call = $this->makeCall();
        $this->end($call);
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/objects/calls'));
        $this->assertNull($call->refresh()->hubspot_call_id);

        $this->connect(['create_contacts' => true]);
        $this->end($this->makeCall());
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/objects/contacts') && $r['properties']['phone'] === '+447700900123');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/objects/calls') && $r['associations'][0]['to']['id'] === '777');
    }

    public function test_internal_calls_transfers_and_other_plans_are_never_sent(): void
    {
        $this->admin();
        $this->connect();
        $this->fakeHubSpot();

        $this->end($this->makeCall(['direction' => 'internal', 'from' => '201', 'to' => '202']));
        $this->end($this->makeCall(['leg' => Call::LEG_TRANSFER]));
        $this->end($this->makeCall(['from' => 'anonymous']));
        Http::assertNothingSent();

        $this->connect(['log_calls' => false]);
        $this->end($this->makeCall());
        Http::assertNothingSent();
    }

    public function test_the_summary_and_recording_link_are_added_to_the_same_hubspot_call(): void
    {
        $this->admin();
        $this->connect();
        $this->fakeHubSpot();

        $call = $this->makeCall();
        $this->end($call);
        $recording = Recording::create(['carrier_sid' => 'rec-1', 'kind' => 'call', 'call_id' => $call->id, 'status' => 'pending']);
        $recording->forceFill(['status' => 'stored'])->save();
        $recording->forceFill(['summary' => 'Wants <b>a quote</b>', 'action_items' => ['Send quote'], 'sentiment' => 'positive', 'ai_status' => 'done'])->save();

        Http::assertSent(function (Request $r) use ($recording) {
            return $r->method() === 'PATCH' && str_ends_with($r->url(), '/objects/calls/9001')
                && str_contains($r['properties']['hs_call_body'], 'Wants &lt;b&gt;a quote&lt;/b&gt;')
                && str_contains($r['properties']['hs_call_body'], 'Send quote')
                && str_contains($r['properties']['hs_call_body'], route('recordings.show', $recording));
        });
        // One HubSpot call was made, then updated: never a second one.
        $created = Http::recorded()->filter(fn ($pair) => $pair[0]->method() === 'POST' && str_ends_with($pair[0]->url(), '/objects/calls'));
        $this->assertCount(1, $created);

        $this->connect(['include_summary' => false, 'include_recording_link' => false]);
        $recording->forceFill(['ai_status' => 'queued'])->save();
        $recording->forceFill(['ai_status' => 'done'])->save();
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r['properties']['hs_call_body'] === '');
    }

    public function test_a_busy_hubspot_is_retried_but_a_wrong_token_is_not(): void
    {
        $this->admin();
        $this->connect();
        \Illuminate\Support\Facades\Queue::fake();

        Http::fake(['*' => Http::sequence()->push('', 503)->push(['message' => 'x'], 401)]);
        $call = $this->makeCall();
        $call->forceFill(['status' => 'completed', 'duration_seconds' => 30])->save();
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\SyncHubSpotCall::class, 1);

        app()->call([new \App\Jobs\SyncHubSpotCall($call->id), 'handle']);
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\SyncHubSpotCall::class, fn ($job) => $job->attempt === 2 && $job->delay === 60);
        $this->assertFalse(Setting::get('hubspot_last')['ok']);

        \Illuminate\Support\Facades\Queue::fake();
        app()->call([new \App\Jobs\SyncHubSpotCall($call->id), 'handle']);
        \Illuminate\Support\Facades\Queue::assertNothingPushed();
        $this->assertStringContainsString('refused the token', Setting::get('hubspot_last')['error']);
    }

    private function inbound(int $call, string $from = '+447700900123'): string
    {
        return $this->voicePost('/webhooks/voice/inbound', [
            'session_id' => self::callId($call), 'Caller-Caller-ID-Number' => $from, 'Caller-Destination-Number' => '+442079460000',
        ])->getContent();
    }

    private function nameLookup(string $first = 'Sam', string $last = 'Taylor', string $company = 'Hilltop Builders'): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(['api.hubapi.com/crm/v3/objects/contacts/search' => Http::response(['results' => [['id' => '501', 'properties' => ['firstname' => $first, 'lastname' => $last, 'company' => $company]]]])]);
    }

    public function test_an_incoming_call_shows_the_hubspot_name_on_the_phones_and_in_the_history(): void
    {
        $owner = $this->admin();
        $this->connect();
        \App\Models\PhoneNumber::create(['e164' => '+442079460000', 'destination' => "user:{$owner->id}"]);
        $this->nameLookup();

        $xml = $this->inbound(1);

        $this->assertStringContainsString('effective_caller_id_name=Sam Taylor (Hilltop Builders)', $xml);
        $this->assertLessThan(strpos($xml, 'bridge'), strpos($xml, 'effective_caller_id_name'), 'The name is set before the call is dialled.');
        $this->assertDatabaseHas('calls', ['carrier_sid' => self::callId(1), 'caller_name' => 'Sam Taylor (Hilltop Builders)']);
        $this->actingAs($owner)->get('/admin/call-logs')->assertSee('Sam Taylor (Hilltop Builders)');
    }

    public function test_names_are_remembered_so_the_same_caller_is_not_looked_up_twice(): void
    {
        $owner = $this->admin();
        $this->connect();
        \App\Models\PhoneNumber::create(['e164' => '+442079460000', 'destination' => "user:{$owner->id}"]);
        $this->nameLookup();

        $this->inbound(2);
        $this->inbound(3);

        Http::assertSentCount(1);
    }

    public function test_a_slow_or_failing_hubspot_never_holds_up_calls(): void
    {
        $owner = $this->admin();
        $this->connect();
        \App\Models\PhoneNumber::create(['e164' => '+442079460000', 'destination' => "user:{$owner->id}"]);
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(['*' => Http::response('', 503)]);

        $this->assertStringContainsString('bridge', $this->inbound(4));
        $this->assertStringNotContainsString('effective_caller_id_name', $this->inbound(5, '+447700900124'));
        Http::assertSentCount(1); // after one failure it stops asking for a couple of minutes
        $this->assertDatabaseHas('calls', ['carrier_sid' => self::callId(5), 'caller_name' => null]);
    }

    public function test_no_lookup_when_switched_off_unknown_numbers_or_not_pro(): void
    {
        $owner = $this->admin();
        $this->connect(['lookup_names' => false]);
        \App\Models\PhoneNumber::create(['e164' => '+442079460000', 'destination' => "user:{$owner->id}"]);
        $this->nameLookup();

        $this->inbound(6);
        $this->connect();
        $this->inbound(7, 'anonymous');
        Http::assertNothingSent();

        $this->usePlan(Plan::Plus);
        $this->inbound(8);
        Http::assertNothingSent();
    }

    public function test_a_hubspot_name_cannot_inject_into_the_voice_server(): void
    {
        $owner = $this->admin();
        $this->connect();
        \App\Models\PhoneNumber::create(['e164' => '+442079460000', 'destination' => "user:{$owner->id}"]);
        $this->nameLookup('Sam', "Taylor\n<action application=\"hangup\"/>" . '$' . '{x}', 'A;B');

        $xml = $this->inbound(9);

        $this->assertStringNotContainsString('<action application="hangup"', $xml);
        $this->assertStringNotContainsString('${', $xml);
        preg_match('/effective_caller_id_name=([^"]*)"/', $xml, $m);
        $this->assertDoesNotMatchRegularExpression('/[<>${};="\\n]/', $m[1]);
        $this->assertStringStartsWith('Sam Taylor', $m[1]);
    }
}
