<?php

namespace Tests\Feature;

use App\Domain\Licensing\Plan;
use App\Mail\ApiCallAlert;
use App\Models\ApiCall;
use App\Models\ApiToken;
use App\Models\Call;
use App\Models\Setting;
use App\Models\SipTrunk;
use App\Models\User;
use App\Voice\FreeSwitch\FreeSwitchServer;
use App\Voice\VoiceServer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Placing calls over the API (Pro, #119). */
class ApiCallsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $ann;

    private string $token;

    private ApiToken $calls;

    private object $voice;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->usePlan(Plan::Pro);
        $this->owner = User::factory()->owner()->withTwoFactor()->create();
        $this->acknowledgeTerms($this->owner);
        $this->ann = User::factory()->create(['name' => 'Ann Agent']);
        config(['voip.caller_id' => '+442079460000']);
        SipTrunk::create(['name' => 'HQ', 'provider' => 'other', 'inbound_ips' => [['ip' => '203.0.113.10', 'prefix' => 32]], 'origination_uris' => ['sip:sbc.example.net'], 'status' => 'active'])
            ->forceFill(['default_outbound' => true])->save();

        $this->voice = new class(config('voip.freeswitch')) extends FreeSwitchServer
        {
            public array $rung = [];

            public bool $fail = false;

            public function callUser(array $sipUsernames, string $callId, string $label, string $url): void
            {
                if ($this->fail) {
                    throw new \RuntimeException('down');
                }
                $this->rung[] = compact('sipUsernames', 'callId', 'label', 'url');
            }
        };
        $this->app->instance(VoiceServer::class, $this->voice);

        [$this->calls, $this->token] = ApiToken::make('CRM', 'calls', ['allowed_user_ids' => [$this->ann->id], 'allowed_prefixes' => []]);
    }

    private function place(array $body, ?string $token = null, array $headers = [])
    {
        return $this->withToken($token ?? $this->token)->postJson('/api/v1/calls', $body, $headers);
    }

    public function test_a_calling_token_rings_the_persons_phones_and_logs_the_request(): void
    {
        $response = $this->place(['user_id' => $this->ann->id, 'to' => '+44 7700 900123'])->assertStatus(202)->assertJsonPath('data.status', 'requested')->assertJsonPath('data.to', '+447700900123');

        $ring = $this->voice->rung[0];
        $this->assertSame(["user-{$this->ann->id}"], $ring['sipUsernames']);
        $this->assertSame('Ann Agent', $ring['label']);
        $this->assertStringContainsString('/webhooks/voice/api-call?request='.$response->json('data.id'), $ring['url']);

        $api = ApiCall::query()->sole();
        $this->assertSame(['CRM', $this->ann->id, '+447700900123', '+44', 'requested', $ring['callId']], [$api->token_name, $api->user_id, $api->to, $api->prefix, $api->status, $api->call_id]);
        $this->assertDatabaseHas('calls', ['carrier_sid' => $ring['callId'], 'api_call_id' => $api->id, 'user_id' => $this->ann->id, 'direction' => 'outbound']);
        $this->withToken($this->token)->getJson('/api/v1/call-requests/'.$api->id)->assertOk()->assertJsonPath('data.status', 'requested');
    }

    public function test_nothing_is_dialled_outbound_until_the_person_answers_then_the_normal_rules_run(): void
    {
        $this->place(['user_id' => $this->ann->id, 'to' => '+447700900123']);
        $ring = $this->voice->rung[0];
        $api = ApiCall::query()->sole();

        $xml = $this->voicePost('/webhooks/voice/api-call?request='.$api->id, ['session_id' => $ring['callId']])->assertOk()->getContent();

        $this->assertStringContainsString('Connecting your call.', $xml);
        $this->assertStringContainsString('+447700900123', $xml);
        $this->assertStringContainsString('application="bridge"', $xml);
        $this->assertSame('answered', $api->refresh()->status);
        $this->assertDatabaseHas('calls', ['carrier_sid' => $ring['callId'], 'decision' => 'number', 'to' => '+447700900123']);
    }

    public function test_the_calling_rules_still_apply_when_they_answer(): void
    {
        $this->place(['user_id' => $this->ann->id, 'to' => '+447700900123']);
        $ring = $this->voice->rung[0];
        $this->ann->forceFill(['internal_only' => true])->save();

        $xml = $this->voicePost('/webhooks/voice/api-call?request='.ApiCall::query()->value('id'), ['session_id' => $ring['callId']])->getContent();

        $this->assertStringNotContainsString('application="bridge"', $xml);
        $this->assertStringContainsString('This phone can only call colleagues.', $xml);
        $this->assertStringContainsString('<hangup/>', $xml);
        $this->assertSame('refused', ApiCall::query()->value('status'));
    }

    public function test_a_webhook_for_the_wrong_request_or_an_ordinary_call_does_nothing(): void
    {
        $this->place(['user_id' => $this->ann->id, 'to' => '+447700900123']);
        $ring = $this->voice->rung[0];

        $this->assertStringNotContainsString('bridge', $this->voicePost('/webhooks/voice/api-call?request=999', ['session_id' => $ring['callId']])->getContent());
        $plain = Call::create(['carrier_sid' => self::callId(77), 'user_id' => $this->ann->id, 'direction' => 'outbound', 'status' => 'initiated']);
        $this->assertStringNotContainsString('bridge', $this->voicePost('/webhooks/voice/api-call?request=1', ['session_id' => $plain->carrier_sid])->getContent());
    }

    public function test_read_only_tokens_and_lower_plans_cannot_place_calls(): void
    {
        [, $read] = ApiToken::make('Reader');
        $this->place(['user_id' => $this->ann->id, 'to' => '+447700900123'], $read)->assertForbidden();

        $this->usePlan(Plan::Plus);
        $this->place(['user_id' => $this->ann->id, 'to' => '+447700900123'])->assertForbidden();
        $this->assertSame([], $this->voice->rung);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refused')]
    public function test_numbers_and_people_that_are_not_allowed_are_refused_and_recorded(string $to, int $status): void
    {
        $this->place(['user_id' => $this->ann->id, 'to' => $to])->assertStatus($status);

        $this->assertSame([], $this->voice->rung);
        $this->assertSame('refused', ApiCall::query()->sole()->status);
    }

    public static function refused(): array
    {
        return [
            'premium rate' => ['+449098765432', 422], 'personal number' => ['+447012345678', 422], 'international, not listed' => ['+35312345678', 422],
            'not international format' => ['07700900123', 422], 'an extension' => ['201', 422], 'a feature code' => ['*45', 422],
            'emergency' => ['999', 422], 'too short' => ['+4412', 422],
        ];
    }

    public function test_only_the_chosen_people_can_be_rung_and_only_own_company_people(): void
    {
        $ben = User::factory()->create();
        $this->place(['user_id' => $ben->id, 'to' => '+447700900123'])->assertForbidden();
        $this->place(['user_id' => 99999, 'to' => '+447700900123'])->assertForbidden();
        $this->ann->forceFill(['suspended_at' => now()])->save();
        $this->place(['user_id' => $this->ann->id, 'to' => '+447700900123'])->assertForbidden();
        $this->assertSame([], $this->voice->rung);
    }

    public function test_other_countries_only_when_listed_and_the_first_call_to_one_alerts_admins(): void
    {
        $this->calls->forceFill(['allowed_prefixes' => ['+353']])->save();

        $this->place(['user_id' => $this->ann->id, 'to' => '+35312345678'])->assertStatus(202);
        $this->place(['user_id' => $this->ann->id, 'to' => '+33123456789'])->assertStatus(422);
        Mail::assertQueued(ApiCallAlert::class, fn (ApiCallAlert $m) => str_contains($m->what, 'first call to +353'));

        Mail::fake();
        ApiCall::query()->update(['status' => 'answered']);
        Call::query()->update(['duration_seconds' => 5]);
        $this->place(['user_id' => $this->ann->id, 'to' => '+35387654321'])->assertStatus(202);
        Mail::assertNothingQueued(); // not the first any more
    }

    public function test_per_minute_limit_in_progress_cap_and_daily_minutes(): void
    {
        $this->calls->forceFill(['per_minute' => 3, 'daily_minutes' => 5])->save();
        $mk = fn () => $this->place(['user_id' => $this->ann->id, 'to' => '+447700900123']);
        $end = fn () => Call::query()->whereNull('duration_seconds')->update(['duration_seconds' => 1]);

        $mk()->assertStatus(202);
        $mk()->assertStatus(202);
        $mk()->assertStatus(429)->assertJsonPath('error', 'rate_limited'); // two are still going
        $end();
        $mk()->assertStatus(202);
        $end();
        $mk()->assertStatus(429); // three in the last minute
        Mail::assertQueued(ApiCallAlert::class, fn ($m) => str_contains($m->what, 'over its call limit'));

        $this->calls->forceFill(['per_minute' => 60])->save();
        Call::query()->whereNotNull('api_call_id')->update(['duration_seconds' => 200]); // 3 calls x 200 s = 10 minutes
        $mk()->assertStatus(429)->assertJsonPath('message', "This token's daily minutes are used up.");
    }

    public function test_the_same_idempotency_key_is_the_same_call(): void
    {
        $a = $this->place(['user_id' => $this->ann->id, 'to' => '+447700900123'], null, ['Idempotency-Key' => 'abc-1'])->assertStatus(202);
        $b = $this->place(['user_id' => $this->ann->id, 'to' => '+447700900123'], null, ['Idempotency-Key' => 'abc-1'])->assertOk()->assertJsonPath('idempotent', true);

        $this->assertSame($a->json('data.id'), $b->json('data.id'));
        $this->assertCount(1, $this->voice->rung);
        $this->place(['user_id' => $this->ann->id, 'to' => '+447700900123'], null, ['Idempotency-Key' => 'bad key!'])->assertStatus(422);
    }

    public function test_it_can_be_paused_and_the_operator_can_switch_it_off(): void
    {
        Setting::put('api.calls_paused', true);
        $this->place(['user_id' => $this->ann->id, 'to' => '+447700900123'])->assertStatus(503);
        Setting::put('api.calls_paused', false);
        config(['quaverdesk.api_calls_enabled' => false]);
        $this->place(['user_id' => $this->ann->id, 'to' => '+447700900123'])->assertStatus(503);
        config(['quaverdesk.api_calls_enabled' => true]);
        $this->place(['user_id' => $this->ann->id, 'to' => '+447700900123'])->assertStatus(202);
    }

    public function test_a_voice_server_failure_is_reported(): void
    {
        $this->voice->fail = true;

        $this->place(['user_id' => $this->ann->id, 'to' => '+447700900123'])->assertStatus(502);
        $this->assertSame('refused', ApiCall::query()->sole()->status);
        $this->assertDatabaseHas('calls', ['status' => 'failed']);
    }

    public function test_the_voice_server_refuses_unsafe_values(): void
    {
        $server = new FreeSwitchServer(config('voip.freeswitch'));
        foreach ([[['user-1; hangup'], self::callId(1)], [['user-1'], 'not-a-uuid'], [[], self::callId(1)]] as [$users, $id]) {
            try {
                $server->callUser($users, $id, 'Ann', 'https://app.test/webhooks/voice/api-call?request=1');
                $this->fail('Expected a refusal.');
            } catch (\RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_an_admin_makes_a_calling_token_once_sets_limits_and_pauses_calling(): void
    {
        $this->actingAs($this->owner);
        $this->post('/admin/api/calling', ['name' => 'Click to call', 'users' => [$this->ann->id], 'countries' => '+353, 33, +44', 'per_minute' => 5, 'per_hour' => 30, 'daily_minutes' => 60])->assertSessionHasNoErrors();
        $plain = session('api_token');
        $token = ApiToken::query()->where('name', 'Click to call')->firstOrFail();

        $this->assertTrue($token->canCall());
        $this->assertSame([$this->ann->id], $token->allowed_user_ids);
        $this->assertSame(['+353', '+33'], $token->allowed_prefixes, 'The UK is always allowed and is not listed.');
        $this->assertSame([5, 30, 60], [$token->per_minute, $token->per_hour, $token->daily_minutes]);
        Mail::assertQueued(ApiCallAlert::class, fn ($m) => str_contains($m->what, 'can place calls'));

        $this->get('/admin/api')->assertSee($plain)->assertSee('Can place calls');
        $this->get('/admin/api')->assertDontSee($plain);
        $this->post('/admin/api/calling', ['name' => 'No people', 'per_minute' => 5, 'per_hour' => 30, 'daily_minutes' => 60])->assertSessionHasErrors('users');

        $this->post('/admin/api/calling/pause', ['paused' => 1]);
        $this->assertTrue((bool) Setting::get('api.calls_paused'));
        $this->get('/admin/api')->assertSee('Switch back on');

        $this->usePlan(Plan::Plus);
        $this->post('/admin/api/calling', ['name' => 'X', 'users' => [$this->ann->id], 'per_minute' => 5, 'per_hour' => 30, 'daily_minutes' => 60])->assertRedirect('/dashboard');
    }

    public function test_the_audit_trail_shows_in_the_admin_page_and_calls_are_marked_api(): void
    {
        $this->place(['user_id' => $this->ann->id, 'to' => '+447700900123']);
        $this->place(['user_id' => $this->ann->id, 'to' => '+449098765432']);

        $this->actingAs($this->owner)->get('/admin/api')->assertSee('+447700900123')->assertSee('+449098765432')->assertSee('Premium-rate')->assertSee('CRM');
        $this->get('/admin/call-logs')->assertSee('API');
    }
}
