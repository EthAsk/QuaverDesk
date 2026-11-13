<?php

namespace Tests\Feature;

use App\Domain\Licensing\Plan;
use App\Models\CallQueue;
use App\Models\Setting;
use App\Models\User;
use App\Sso\ScimToken;
use App\Sso\SsoSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Directory sync (SCIM 2.0) for Microsoft Entra, Okta and others (Pro, #30). */
class ScimTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->usePlan(Plan::Pro, 5);
        $this->owner = User::factory()->owner()->withTwoFactor()->create(['email' => 'boss@example.co.uk']);
        $this->acknowledgeTerms($this->owner);
        $this->token = app(ScimToken::class)->make();
    }

    private function scim(string $method, string $uri, array $data = [], ?string $token = null)
    {
        return $this->withToken($token ?? $this->token)->json($method, '/scim/v2'.$uri, $data);
    }

    private function create(string $email = 'ann@example.co.uk', array $extra = [])
    {
        return $this->scim('POST', '/Users', $extra + [
            'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:User'], 'externalId' => 'ann-nick', 'userName' => $email,
            'name' => ['givenName' => 'Ann', 'familyName' => 'Agent'], 'displayName' => 'Ann Agent',
            'emails' => [['value' => $email, 'type' => 'work', 'primary' => true]], 'active' => true,
        ]);
    }

    public function test_it_needs_the_token_and_the_pro_plan(): void
    {
        $this->withToken('nope')->getJson('/scim/v2/Users')->assertUnauthorized();
        $this->getJson('/scim/v2/Users', ['Authorization' => ''])->assertUnauthorized();
        $this->scim('GET', '/Users')->assertOk()->assertHeader('Content-Type', 'application/scim+json');

        $this->usePlan(Plan::Plus);
        $this->scim('GET', '/Users')->assertUnauthorized();
    }

    public function test_only_a_hash_of_the_token_is_kept_and_a_new_one_replaces_it(): void
    {
        $this->assertStringNotContainsString($this->token, (string) Setting::query()->where('key', 'scim')->value('value'));
        $new = app(ScimToken::class)->make();
        $this->scim('GET', '/Users')->assertUnauthorized();
        $this->scim('GET', '/Users', [], $new)->assertOk();
        app(ScimToken::class)->revoke();
        $this->scim('GET', '/Users', [], $new)->assertUnauthorized();
    }

    public function test_the_directory_adds_a_person_as_an_ordinary_user(): void
    {
        $response = $this->create()->assertCreated();
        $id = $response->json('id');

        $user = User::query()->findOrFail($id);
        $this->assertSame(['Ann Agent', 'ann@example.co.uk', User::ROLE_USER, 'ann-nick'], [$user->name, $user->email, $user->role, $user->scim_external_id]);
        $this->assertNotNull($user->extension);
        $this->assertFalse($user->isSuspended());
        $response->assertJsonPath('userName', 'ann@example.co.uk')->assertJsonPath('active', true)->assertJsonPath('name.givenName', 'Ann');
    }

    public function test_a_person_added_here_can_sign_in_with_the_provider(): void
    {
        app(SsoSettings::class)->save('microsoft', true, 'ms-client', 'ms-secret', '11111111-2222-3333-4444-555555555555');
        $this->create();

        $ann = User::query()->where('email', 'ann@example.co.uk')->firstOrFail();
        $this->assertTrue($ann->canSignIn());
        $this->assertSame(3, User::query()->seated()->count() + 1);
    }

    public function test_duplicates_a_bad_email_and_a_full_plan_are_refused(): void
    {
        $this->create()->assertCreated();
        $this->create()->assertStatus(409)->assertJsonPath('scimType', 'uniqueness');
        $this->create('boss@example.co.uk')->assertStatus(409);
        $this->scim('POST', '/Users', ['userName' => 'not-an-email'])->assertStatus(400);

        $this->usePlan(Plan::Pro, 2);
        $this->create('ben@example.co.uk')->assertStatus(409)->assertJsonPath('detail', fn ($d) => str_contains($d, 'all in use'));
        $this->assertFalse(User::query()->where('email', 'ben@example.co.uk')->exists());
    }

    public function test_people_are_found_by_username_or_external_id(): void
    {
        $this->create();
        $this->scim('GET', '/Users?filter='.urlencode('userName eq "Ann@Example.co.uk"'))->assertJsonPath('totalResults', 1)->assertJsonPath('Resources.0.displayName', 'Ann Agent');
        $this->scim('GET', '/Users?filter='.urlencode('externalId eq "ann-nick"'))->assertJsonPath('totalResults', 1);
        $this->scim('GET', '/Users?filter='.urlencode('userName eq "nobody@example.co.uk"'))->assertJsonPath('totalResults', 0);
        $this->scim('GET', '/Users?filter='.urlencode('displayName co "x"'))->assertStatus(400);
        $this->scim('GET', '/Users')->assertJsonPath('totalResults', 2);
    }

    public function test_switching_someone_off_ends_their_sign_in_and_queues_but_deletes_nothing(): void
    {
        $id = $this->create()->json('id');
        $ann = User::query()->findOrFail($id);
        $queue = CallQueue::create(['name' => 'Support']);
        $queue->members()->attach($ann->id, ['logged_in' => true]);
        DB::table('sessions')->insert(['id' => 'abc', 'user_id' => $ann->id, 'ip_address' => '1.1.1.1', 'user_agent' => 'x', 'payload' => '', 'last_activity' => time()]);

        // Entra sends the flag as text.
        $this->scim('PATCH', "/Users/{$id}", ['Operations' => [['op' => 'Replace', 'path' => 'active', 'value' => 'False']]])->assertOk()->assertJsonPath('active', false);

        $ann->refresh();
        $this->assertTrue($ann->isSuspended());
        $this->assertFalse($ann->canSignIn());
        $this->assertSame(0, DB::table('sessions')->where('user_id', $ann->id)->count());
        $this->assertFalse((bool) DB::table('call_queue_user')->where('user_id', $ann->id)->value('logged_in'));
        $this->assertTrue($queue->members()->whereKey($ann->id)->exists(), 'They stay in the queue, just logged out.');

        $this->post('/login', ['email' => 'ann@example.co.uk', 'password' => 'anything'])->assertSessionHasErrors();
        $this->assertGuest();

        // Back again.
        $this->scim('PATCH', "/Users/{$id}", ['Operations' => [['op' => 'replace', 'value' => ['active' => true]]]])->assertJsonPath('active', true);
        $this->assertTrue($ann->refresh()->canSignIn());
    }

    public function test_deleting_in_the_directory_only_switches_off(): void
    {
        $id = $this->create()->json('id');

        $this->scim('DELETE', "/Users/{$id}")->assertNoContent();

        $this->assertTrue(User::query()->findOrFail($id)->isSuspended());
    }

    public function test_names_and_emails_are_kept_in_step(): void
    {
        $id = $this->create()->json('id');

        $this->scim('PATCH', "/Users/{$id}", ['Operations' => [
            ['op' => 'Replace', 'path' => 'name.familyName', 'value' => 'Agent-Smith'],
            ['op' => 'Replace', 'path' => 'emails[type eq "work"].value', 'value' => 'ann.smith@example.co.uk'],
            ['op' => 'Replace', 'path' => 'urn:something:else', 'value' => 'ignored'],
        ]])->assertOk();
        $ann = User::query()->findOrFail($id);
        $this->assertSame(['Ann Agent-Smith', 'ann.smith@example.co.uk'], [$ann->name, $ann->email]);

        $this->scim('PUT', "/Users/{$id}", ['userName' => 'ann.smith@example.co.uk', 'displayName' => 'Annie Smith', 'active' => true])->assertOk();
        $this->assertSame('Annie Smith', $ann->refresh()->name);

        // An email someone else has is not taken over.
        $this->scim('PATCH', "/Users/{$id}", ['Operations' => [['op' => 'Replace', 'path' => 'userName', 'value' => 'boss@example.co.uk']]])->assertOk();
        $this->assertSame('ann.smith@example.co.uk', $ann->refresh()->email);
    }

    public function test_the_owner_cannot_be_changed_from_the_directory(): void
    {
        $this->scim('PATCH', "/Users/{$this->owner->id}", ['Operations' => [['op' => 'Replace', 'path' => 'active', 'value' => false]]])->assertForbidden();
        $this->scim('PUT', "/Users/{$this->owner->id}", ['userName' => 'x@example.co.uk', 'active' => false])->assertForbidden();
        $this->scim('DELETE', "/Users/{$this->owner->id}")->assertForbidden();
        $this->assertFalse($this->owner->refresh()->isSuspended());
    }

    public function test_service_provider_config_and_unknown_people(): void
    {
        $this->scim('GET', '/ServiceProviderConfig')->assertOk()->assertJsonPath('patch.supported', true);
        $this->scim('GET', '/Users/99999')->assertNotFound()->assertJsonPath('status', '404');
        $this->scim('PATCH', '/Users/99999', ['Operations' => []])->assertNotFound();
    }

    public function test_an_admin_turns_it_on_sees_the_token_once_and_reactivates_people(): void
    {
        $this->actingAs($this->owner);
        $this->post('/admin/sso/directory-token')->assertRedirect();
        $token = session('scim_token');
        $this->assertStringStartsWith('qdscim_', $token);
        $this->get('/admin/sso')->assertSee($token)->assertSee(url('/scim/v2'));
        $this->get('/admin/sso')->assertDontSee($token);
        $this->scim('GET', '/Users')->assertUnauthorized(); // the earlier token was replaced
        $this->token = $token;
        $this->scim('GET', '/Users')->assertOk();

        $id = $this->create()->json('id');
        $this->scim('DELETE', "/Users/{$id}");
        $this->get('/admin/users')->assertSee('Switched off by your directory')->assertSee('Reactivate');
        $this->patch("/admin/users/{$id}/reactivate")->assertRedirect();
        $this->assertFalse(User::query()->findOrFail($id)->isSuspended());

        $this->delete('/admin/sso/directory-token')->assertRedirect();
        $this->scim('GET', '/Users', [], $token)->assertUnauthorized();
    }

    public function test_a_company_cannot_see_or_change_another_companys_people(): void
    {
        config(['tenancy.enabled' => true, 'tenancy.base_domain' => 'quaverdesk.test']);
        $tenancy = app(\App\Tenancy\Tenancy::class);
        $keys = \App\Domain\Licensing\LicenceCodec::generateKeyPair();
        config(['voip.licence_public_key' => $keys['public']]);
        $acme = tap(\App\Models\Tenant::query()->create(['name' => 'Acme', 'slug' => 'acme']), fn ($t) => $t->forceFill(['plan' => Plan::Pro, 'seats' => 25])->save());
        $bolt = tap(\App\Models\Tenant::query()->create(['name' => 'Bolt', 'slug' => 'bolt']), fn ($t) => $t->forceFill(['plan' => Plan::Pro, 'seats' => 25])->save());
        $tokens = [];
        $bobId = null;
        foreach ([$acme, $bolt] as $tenant) {
            $tenancy->run($tenant, function () use ($keys, $tenant, &$tokens, &$bobId) {
                Setting::put('licence_key', \App\Domain\Licensing\LicenceCodec::issue($keys['secret'], 'Test Ltd', Plan::Pro, 25, null));
                $tokens[$tenant->slug] = app(ScimToken::class)->make();
                if ($tenant->slug === 'bolt') {
                    $bobId = User::factory()->create(['email' => 'bob@bolt.test'])->id;
                }
            });
        }
        $tenancy->set(null);

        $acmeCall = fn (string $method, string $uri, array $data = [], ?string $token = null) => $this->withToken($token ?? $tokens['acme'])->json($method, "http://acme.quaverdesk.test/scim/v2{$uri}", $data);

        $acmeCall('GET', "/Users/{$bobId}")->assertNotFound();
        $acmeCall('PATCH', "/Users/{$bobId}", ['Operations' => [['op' => 'Replace', 'path' => 'active', 'value' => false]]])->assertNotFound();
        $acmeCall('DELETE', "/Users/{$bobId}")->assertNotFound();
        $acmeCall('GET', '/Users')->assertJsonPath('totalResults', 0);
        // Bolt's token on Acme's address, and the other way round, are not accepted.
        $acmeCall('GET', '/Users', [], $tokens['bolt'])->assertUnauthorized();
        $this->assertFalse($tenancy->run($bolt, fn () => User::query()->findOrFail($bobId)->isSuspended()));
    }
}
