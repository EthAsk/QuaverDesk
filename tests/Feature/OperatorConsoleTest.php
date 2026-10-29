<?php

namespace Tests\Feature;

use App\Domain\Licensing\LicenceCodec;
use App\Domain\Licensing\Plan;
use App\Mail\SupportVisitStarted;
use App\Mail\UserInvited;
use App\Models\Call;
use App\Models\Operator;
use App\Models\OperatorAudit;
use App\Models\PhoneNumber;
use App\Models\Setting;
use App\Models\SipTrunk;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/** The hosted service's operator console. */
class OperatorConsoleTest extends TestCase
{
    use RefreshDatabase;

    private const CONSOLE = 'http://operator.quaverdesk.test/operator';

    private Tenancy $tenancy;
    private Tenant $acme;
    private User $acmeOwner;

    protected function setUp(): void
    {
        parent::setUp();
        config(['tenancy.enabled' => true, 'tenancy.base_domain' => 'quaverdesk.test', 'tenancy.operator_host' => 'operator.quaverdesk.test']);
        $this->tenancy = app(Tenancy::class);
        $this->acme = Tenant::query()->create(['name' => 'Acme', 'slug' => 'acme']);
        $this->acmeOwner = $this->tenancy->run($this->acme, function () {
            $owner = User::factory()->owner()->create(['name' => 'Ann Acme']);
            $this->acknowledgeTerms($owner);

            return $owner;
        });
        $this->tenancy->set(null);
    }

    private function operator(bool $twoFactor = true): Operator
    {
        $operator = Operator::query()->create(['name' => 'Olly Operator', 'email' => 'olly@quaverdesk.test', 'password' => 'a-long-operator-password-1']);
        if ($twoFactor) {
            $operator->forceFill(['two_factor_secret' => (new Google2FA())->generateSecretKey(32), 'two_factor_confirmed_at' => now()])->save();
        }

        return $operator;
    }

    private function code(string $secret): string
    {
        return (new Google2FA())->getCurrentOtp($secret);
    }

    private function signIn(): Operator
    {
        $operator = $this->operator();
        $this->actingAs($operator, 'operator');

        return $operator;
    }

    /**
     * Tests share one auth manager across requests, so after a console request
     * the default guard is still the operator's. Real requests on a company's
     * address never carry the operator's cookie.
     */
    private function onCompanyAddress(): void
    {
        auth()->shouldUse('web');
    }

    public function test_the_console_exists_only_on_its_own_address(): void
    {
        $this->get(self::CONSOLE.'/login')->assertOk();
        $this->get('http://acme.quaverdesk.test/operator/login')->assertNotFound();
        $this->get('http://operator.quaverdesk.test/login')->assertNotFound();
        $this->get('http://operator.quaverdesk.test/dashboard')->assertNotFound();

        config(['tenancy.enabled' => false]);
        $this->get('http://localhost/operator/login')->assertNotFound();
    }

    public function test_signing_in_needs_the_password_then_a_fresh_code(): void
    {
        $operator = $this->operator();
        $code = $this->code($operator->two_factor_secret);

        $this->get(self::CONSOLE.'/companies')->assertRedirectContains('/operator/login');
        $this->post(self::CONSOLE.'/login', ['email' => $operator->email, 'password' => 'wrong-password'])->assertSessionHasErrors('email');
        $this->post(self::CONSOLE.'/login', ['email' => $this->acmeOwner->email, 'password' => 'password-1234'])->assertSessionHasErrors('email');

        $this->post(self::CONSOLE.'/login', ['email' => $operator->email, 'password' => 'a-long-operator-password-1'])->assertRedirectContains('/operator/two-factor');
        $this->assertGuest('operator');
        $this->post(self::CONSOLE.'/two-factor', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->post(self::CONSOLE.'/two-factor', ['code' => $code])->assertRedirectContains('/operator/companies');
        $this->assertAuthenticatedAs($operator, 'operator');

        // The same code can't be used again.
        $this->post(self::CONSOLE.'/logout');
        $this->post(self::CONSOLE.'/login', ['email' => $operator->email, 'password' => 'a-long-operator-password-1']);
        $this->post(self::CONSOLE.'/two-factor', ['code' => $code])->assertSessionHasErrors('code');
        $this->assertGuest('operator');

        $this->assertSame(['login.failed', 'login.failed', 'login.failed', 'login', 'login.failed'], OperatorAudit::query()->orderBy('id')->pluck('action')->all());
    }

    public function test_an_operator_sets_up_two_factor_before_getting_in(): void
    {
        $operator = $this->operator(twoFactor: false);
        $this->post(self::CONSOLE.'/login', ['email' => $operator->email, 'password' => 'a-long-operator-password-1']);

        $this->get(self::CONSOLE.'/two-factor')->assertOk()->assertSee('<svg', false)->assertSee('Set up two-factor');
        $secret = session('operator.setup_secret');
        $this->post(self::CONSOLE.'/two-factor', ['code' => $this->code($secret)])->assertRedirectContains('/operator/companies');

        $this->assertTrue($operator->fresh()->hasTwoFactor());
        $this->assertAuthenticated('operator');
    }

    public function test_adding_a_company_invites_its_owner_on_the_companys_address(): void
    {
        Mail::fake();
        $this->signIn();

        $this->post(self::CONSOLE.'/companies', ['name' => 'Crane Ltd', 'slug' => 'Crane', 'owner_name' => 'Cat', 'owner_email' => 'cat@crane.test'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->post(self::CONSOLE.'/companies', ['name' => 'Dup', 'slug' => 'www', 'owner_name' => 'X', 'owner_email' => $this->acmeOwner->email])
            ->assertSessionHasErrors(['slug', 'owner_email']);

        $crane = Tenant::query()->firstWhere('slug', 'crane');
        $owner = $this->tenancy->run($crane, fn () => User::query()->sole());
        $this->assertTrue($owner->isOwner());
        Mail::assertQueued(UserInvited::class, fn (UserInvited $m) => $m->user->is($owner) && str_starts_with($m->link, 'http://crane.quaverdesk.test/invite/'));
        $this->assertDatabaseHas('operator_audit', ['action' => 'company.created', 'tenant_id' => $crane->id]);
    }

    public function test_minutes_are_split_by_direction_and_by_whose_trunk(): void
    {
        [$ours, $theirs] = $this->tenancy->run($this->acme, function () {
            $make = fn (string $name) => SipTrunk::create(['name' => $name, 'provider' => 'other', 'inbound_ips' => [['ip' => '203.0.113.1', 'prefix' => 32]],
                'origination_uris' => ['sip:sbc.example.net'], 'status' => 'active']);
            [$ours, $theirs] = [$make('Our trunk'), $make('Their trunk')];
            $call = fn (int $n, string $dir, string $status, int $secs, SipTrunk $t, $at = null) => Call::create(['carrier_sid' => self::callId($n),
                'direction' => $dir, 'status' => $status, 'duration_seconds' => $secs, 'sip_trunk_id' => $t->id])->forceFill(['created_at' => $at ?? now()])->save();
            $call(1, 'inbound', 'completed', 600, $ours);        // 10 min in, ours
            $call(2, 'inbound', 'no-answer', 61, $theirs);       // 2 min in, theirs (the trunk was connected)
            $call(3, 'outbound', 'completed', 300, $ours);       // 5 min out, ours
            $call(4, 'outbound', 'completed', 59, $theirs);      // 1 min out, theirs
            $call(5, 'outbound', 'busy', 30, $ours);             // never connected: not counted
            $call(6, 'internal', 'completed', 900, $ours);       // colleague call: not counted
            $call(7, 'outbound', 'completed', 120, $ours, now()->subMonthNoOverflow()->startOfMonth()->addDay()); // last month

            return [$ours, $theirs];
        });
        $this->signIn();
        $show = self::CONSOLE.'/companies/'.$this->acme->id;

        // Nothing is ours until we say so.
        $this->get(self::CONSOLE.'/companies')->assertOk()->assertSeeInOrder(['Acme', 'Active', '12', '6']);
        $this->post($show.'/trunks/'.$ours->id, ['supplied' => 1])->assertSessionHasNoErrors();
        $this->assertTrue($ours->fresh()->supplied_by_operator);
        $this->assertDatabaseHas('operator_audit', ['action' => 'trunk.supplied', 'tenant_id' => $this->acme->id]);

        $this->get(self::CONSOLE.'/companies')->assertSeeInOrder(['12', '(10 ours)', '6', '(5 ours)']);
        $this->get($show)->assertOk()
            ->assertSeeInOrder(['This month, inbound', '10', '2', '12', 'This month, outbound', '5', '1', '6', 'Last month, inbound', '0', '0', '0', 'Last month, outbound', '2', '0', '2']);

        // Another company's trunk can't be marked from this company's page.
        $other = Tenant::query()->create(['name' => 'Bolt', 'slug' => 'bolt']);
        $this->post(self::CONSOLE.'/companies/'.$other->id.'/trunks/'.$theirs->id, ['supplied' => 1])->assertNotFound();
        $this->assertFalse($theirs->fresh()->supplied_by_operator);
    }

    public function test_calls_record_the_trunk_that_carried_them(): void
    {
        $keys = LicenceCodec::generateKeyPair();
        config(['voip.licence_public_key' => $keys['public']]);
        $this->tenancy->run($this->acme, function () use ($keys) {
            Setting::put('licence_key', LicenceCodec::issue($keys['secret'], 'Acme', Plan::Plus, 5, null));
            $trunk = SipTrunk::create(['name' => 'T', 'provider' => 'other', 'inbound_ips' => [['ip' => '203.0.113.20', 'prefix' => 32]],
                'origination_uris' => ['sip:sbc.example.net'], 'status' => 'active', 'default_outbound' => true]);
            PhoneNumber::create(['e164' => '+442079460000', 'sip_trunk_id' => $trunk->id, 'destination' => 'hangup']);
        });
        $this->voicePost('/webhooks/voice/inbound', ['session_id' => self::callId(1), 'Caller-Destination-Number' => '+442079460000', 'source' => '203.0.113.20']);
        $this->voicePost('/webhooks/voice/outgoing', ['session_id' => self::callId(2), 'from' => 'user-'.$this->acmeOwner->id, 'Caller-Destination-Number' => '07700900123']);
        $this->voicePost('/webhooks/voice/outgoing', ['session_id' => self::callId(3), 'from' => 'user-'.$this->acmeOwner->id, 'Caller-Destination-Number' => '201']);

        $trunks = $this->tenancy->run($this->acme, fn () => Call::query()->orderBy('id')->pluck('sip_trunk_id')->all());
        $this->assertNotNull($trunks[0]);
        $this->assertSame($trunks[0], $trunks[1]);
        $this->assertNull($trunks[2], 'colleague calls use no trunk');
    }

    public function test_suspend_export_and_delete(): void
    {
        $this->signIn();
        $show = self::CONSOLE.'/companies/'.$this->acme->id;

        $this->delete($show, ['confirm' => 'acme'])->assertSessionHasErrors('confirm'); // still active
        $this->post($show.'/status', ['status' => 'suspended']);
        $this->assertFalse($this->acme->fresh()->isActive());

        $export = $this->post($show.'/export')->assertOk();
        $this->assertStringContainsString('attachment', (string) $export->headers->get('content-disposition'));

        $this->delete($show, ['confirm' => 'nope'])->assertSessionHasErrors('confirm');
        $this->delete($show, ['confirm' => 'acme'])->assertRedirectContains('/operator/companies');
        $this->assertNull(Tenant::query()->find($this->acme->id));

        $this->assertSame(['company.suspended', 'company.exported', 'company.deleted'], OperatorAudit::query()->orderBy('id')->pluck('action')->all());
        $this->get(self::CONSOLE.'/audit')->assertOk()->assertSee('company.deleted')->assertSee('Acme');
        File::deleteDirectory(storage_path('app/exports'));
    }

    public function test_company_links_keep_the_app_port(): void
    {
        config(['app.url' => 'http://localhost:8000']);
        $this->assertSame('http://acme.quaverdesk.test:8000', $this->acme->url());

        config(['app.url' => 'https://quaverdesk.co.uk']);
        $this->assertSame('https://acme.quaverdesk.test', $this->acme->url());
    }

    public function test_support_visits_are_announced_single_use_and_time_limited(): void
    {
        Mail::fake();
        $this->signIn();

        $this->post(self::CONSOLE.'/companies/'.$this->acme->id.'/support', ['user_id' => $this->acmeOwner->id, 'reason' => 'short'])
            ->assertSessionHasErrors('reason');
        $redirect = $this->post(self::CONSOLE.'/companies/'.$this->acme->id.'/support', ['user_id' => $this->acmeOwner->id, 'reason' => 'Ticket 42: ring group not ringing'])
            ->assertRedirect()->headers->get('location');
        $this->assertMatchesRegularExpression('#^http://acme\.quaverdesk\.test/support/[0-9a-f]{64}$#', $redirect);
        $path = parse_url($redirect, PHP_URL_PATH);
        $this->onCompanyAddress();

        // Only on the company's own address, only once.
        Tenant::query()->create(['name' => 'Bolt', 'slug' => 'bolt']);
        $this->get('http://bolt.quaverdesk.test'.$path)->assertNotFound();
        $this->get($redirect)->assertRedirectContains('/dashboard');
        $this->assertAuthenticatedAs($this->acmeOwner, 'web');
        $this->get('http://acme.quaverdesk.test/dashboard')->assertOk()->assertSee('Support visit')->assertSee('Ticket 42');
        // The operator's own sign-in stands in for the person's password and two-factor (Ann has none).
        $this->assertFalse($this->acmeOwner->hasTwoFactorEnabled());
        $this->get('http://acme.quaverdesk.test/admin/calling-rules')->assertOk();
        $this->get('http://acme.quaverdesk.test/admin/users')->assertOk();
        Mail::assertQueued(SupportVisitStarted::class, fn (SupportVisitStarted $m) => $m->owner->is($this->acmeOwner));
        $this->assertDatabaseHas('operator_audit', ['action' => 'support.started', 'tenant_id' => $this->acme->id]);

        $this->post('http://acme.quaverdesk.test/support/end')->assertRedirectContains('/login');
        $this->assertGuest('web');
        $this->get($redirect)->assertNotFound();

        // A visit ends by itself.
        $this->actingAs(Operator::query()->first(), 'operator');
        $second = $this->post(self::CONSOLE.'/companies/'.$this->acme->id.'/support', ['user_id' => $this->acmeOwner->id, 'reason' => 'Ticket 43: second look'])
            ->headers->get('location');
        $this->onCompanyAddress();
        $this->get($second)->assertRedirectContains('/dashboard');
        $this->travel(61)->minutes();
        $this->get('http://acme.quaverdesk.test/dashboard')->assertRedirectContains('/login');
        $this->assertGuest('web');

        // Signed in as themselves, the same person still needs both.
        $this->actingAs($this->acmeOwner, 'web');
        $this->get('http://acme.quaverdesk.test/admin/users')->assertRedirectContains('/security');
    }
}
