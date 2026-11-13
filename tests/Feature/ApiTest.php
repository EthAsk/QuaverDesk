<?php

namespace Tests\Feature;

use App\Domain\Licensing\Plan;
use App\Models\ApiToken;
use App\Models\Call;
use App\Models\PhoneNumber;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Read-only API for a company's own software (Plus, #8). */
class ApiTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->usePlan(Plan::Plus, 5);
        $this->owner = User::factory()->owner()->withTwoFactor()->create(['name' => 'Alex Owner', 'email' => 'alex@example.co.uk']);
        $this->acknowledgeTerms($this->owner);
        [, $this->token] = ApiToken::make('Dashboard');
    }

    private function api(string $uri, ?string $token = null)
    {
        return $this->withToken($token ?? $this->token)->getJson('/api/v1'.$uri);
    }

    public function test_it_needs_a_token_and_a_plan_that_includes_it(): void
    {
        $this->getJson('/api/v1/users')->assertUnauthorized();
        $this->api('/users', 'qdapi_wrong')->assertUnauthorized();
        $this->api('/users')->assertOk();

        Setting::forget('licence_key');
        $this->app->forgetScopedInstances();
        $this->api('/users')->assertUnauthorized();
    }

    public function test_only_a_hash_of_the_token_is_kept_and_removing_it_stops_it(): void
    {
        $this->assertStringNotContainsString($this->token, json_encode(ApiToken::query()->first()->getAttributes()));
        ApiToken::query()->delete();
        $this->api('/users')->assertUnauthorized();
    }

    public function test_users_and_company(): void
    {
        $ann = User::factory()->create(['name' => 'Ann Agent', 'email' => 'ann@example.co.uk']);

        $this->api('/me')->assertOk()->assertJsonPath('data.plan', 'plus')->assertJsonPath('data.users', 2);
        $list = $this->api('/users')->assertOk()->assertJsonPath('meta.total', 2);
        $this->assertSame(['id', 'name', 'email', 'extension', 'role', 'type', 'suspended'], array_keys($list->json('data.1')));
        $this->api("/users/{$ann->id}")->assertOk()->assertJsonPath('data.email', 'ann@example.co.uk')->assertJsonPath('data.type', 'user');
        $this->api('/users/9999')->assertNotFound();
        $this->assertStringNotContainsString('password', $list->getContent());
    }

    public function test_numbers(): void
    {
        PhoneNumber::create(['e164' => '+442079460000', 'destination' => 'hangup']);

        $this->api('/numbers')->assertOk()->assertJsonPath('data.0.number', '+442079460000');
    }

    public function test_calls_are_listed_newest_first_filtered_and_paged(): void
    {
        $mk = fn (array $a) => Call::create($a + ['carrier_sid' => 'sid-'.uniqid(), 'status' => 'completed', 'duration_seconds' => 30]);
        $in = $mk(['direction' => 'inbound', 'from' => '+447700900123', 'to' => '+442079460000', 'caller_name' => 'Sam Taylor']);
        $out = $mk(['direction' => 'outbound', 'from' => '201', 'to' => '+447700900555', 'user_id' => $this->owner->id]);
        $mk(['direction' => 'inbound', 'from' => '+447700900999', 'to' => '+442079460000', 'leg' => Call::LEG_TRANSFER]);
        $mk(['direction' => 'inbound', 'from' => '+447700900998', 'to' => '+442079460000', 'leg' => Call::LEG_FOLLOW_ME]);

        $all = $this->api('/calls')->assertOk()->assertJsonPath('meta.total', 2);
        $this->assertSame([$out->id, $in->id], array_column($all->json('data'), 'id'));
        $this->assertSame(['id', 'direction', 'from', 'to', 'caller_name', 'status', 'duration_seconds', 'user_id', 'started_at'], array_keys($all->json('data.0')));

        $this->api('/calls?direction=inbound')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.caller_name', 'Sam Taylor');
        $this->api('/calls?number=%2B447700900555')->assertJsonPath('meta.total', 1);
        $this->api("/calls?user_id={$this->owner->id}")->assertJsonPath('meta.total', 1);
        $this->api('/calls?from='.now()->addDay()->toDateString())->assertJsonPath('meta.total', 0);
        $this->api('/calls?per_page=1&page=2')->assertJsonPath('data.0.id', $in->id)->assertJsonPath('meta.last_page', 2);
        $this->api('/calls?direction=sideways')->assertStatus(422);
        $this->api("/calls/{$in->id}")->assertOk()->assertJsonPath('data.from', '+447700900123');
    }

    public function test_an_admin_makes_and_removes_tokens_and_sees_each_token_once(): void
    {
        $this->actingAs($this->owner);
        $this->post('/admin/api', ['name' => 'Reporting'])->assertRedirect();
        $plain = session('api_token');
        $this->assertStringStartsWith('qdapi_', $plain);

        $this->get('/admin/api')->assertSee($plain)->assertSee('Reporting');
        $this->get('/admin/api')->assertDontSee($plain)->assertSee('…'.substr($plain, -4));
        $this->api('/me', $plain)->assertOk();
        $this->assertNotNull(ApiToken::query()->where('name', 'Reporting')->value('last_used_at'));

        $this->delete('/admin/api/'.ApiToken::query()->where('name', 'Reporting')->value('id'))->assertRedirect();
        $this->api('/me', $plain)->assertUnauthorized();
    }

    public function test_a_company_only_sees_its_own_data_and_its_tokens_work_only_on_its_own_address(): void
    {
        config(['tenancy.enabled' => true, 'tenancy.base_domain' => 'quaverdesk.test']);
        $tenancy = app(\App\Tenancy\Tenancy::class);
        $tenants = [];
        $tokens = [];
        foreach (['acme', 'bolt'] as $slug) {
            $tenants[$slug] = tap(\App\Models\Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]), fn ($t) => $t->forceFill(['plan' => Plan::Plus, 'seats' => 25])->save());
            $tenancy->run($tenants[$slug], function () use ($slug, &$tokens) {
                [, $tokens[$slug]] = ApiToken::make('Key');
                User::factory()->create(['name' => ucfirst($slug).' Person']);
                Call::create(['carrier_sid' => 'sid-'.$slug, 'direction' => 'inbound', 'from' => '+447700900123', 'to' => '+442079460000', 'status' => 'completed']);
            });
        }
        $tenancy->set(null);
        $boltCallId = $tenancy->run($tenants['bolt'], fn () => Call::query()->value('id'));

        $get = fn (string $host, string $uri, string $token) => $this->withToken($token)->getJson("http://{$host}.quaverdesk.test/api/v1{$uri}");

        $get('acme', '/users', $tokens['acme'])->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.name', 'Acme Person');
        $get('acme', "/calls/{$boltCallId}", $tokens['acme'])->assertNotFound();
        $get('acme', '/calls', $tokens['acme'])->assertJsonPath('meta.total', 1);
        $get('acme', '/users', $tokens['bolt'])->assertUnauthorized();
        $get('bolt', '/users', $tokens['acme'])->assertUnauthorized();
    }
}
