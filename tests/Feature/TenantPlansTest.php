<?php

namespace Tests\Feature;

use App\Domain\Licensing\Feature;
use App\Domain\Licensing\Plan;
use App\Jobs\Middleware\TakeTurns;
use App\Models\Call;
use App\Models\Operator;
use App\Models\PhoneNumber;
use App\Models\Recording;
use App\Models\Setting;
use App\Models\SipTrunk;
use App\Models\Tenant;
use App\Models\User;
use App\Support\LicenceManager;
use App\Support\RecordingPolicy;
use App\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Each hosted company gets what its plan says, and can't use up what the others share. */
class TenantPlansTest extends TestCase
{
    use RefreshDatabase;

    private Tenancy $tenancy;
    private Tenant $acme;
    private Tenant $bolt;
    private int $n = 100;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['tenancy.enabled' => true, 'tenancy.base_domain' => 'quaverdesk.test', 'tenancy.operator_host' => 'operator.quaverdesk.test']);
        $this->tenancy = app(Tenancy::class);
        $this->acme = Tenant::query()->create(['name' => 'Acme', 'slug' => 'acme']);
        $this->bolt = Tenant::query()->create(['name' => 'Bolt', 'slug' => 'bolt']);

        foreach ([$this->acme, $this->bolt] as $i => $tenant) {
            $this->in($tenant, function () use ($i) {
                $this->acknowledgeTerms(User::factory()->owner()->withTwoFactor()->create());
                $trunk = SipTrunk::create(['name' => 'Trunk', 'provider' => 'other', 'inbound_ips' => [['ip' => '203.0.113.'.(10 + $i), 'prefix' => 32]],
                    'origination_uris' => ['sip:sbc.example.net'], 'status' => 'active', 'default_outbound' => true]);
                PhoneNumber::create(['e164' => '+44207946000'.$i, 'sip_trunk_id' => $trunk->id, 'destination' => 'hangup']);
                Setting::put('calling.caller_id', '+44207946000'.$i);
            });
        }
        $this->tenancy->set(null);
    }

    private function in(Tenant $tenant, \Closure $callback): mixed
    {
        return $this->tenancy->run($tenant, $callback);
    }

    private function owner(Tenant $tenant): User
    {
        return $this->in($tenant, fn () => User::query()->where('role', User::ROLE_OWNER)->first());
    }

    private function dial(Tenant $tenant): string
    {
        return $this->voicePost('/webhooks/voice/outgoing', [
            'session_id' => self::callId($this->n++), 'from' => $this->owner($tenant)->carrierIdentity(), 'Caller-Destination-Number' => '07700900123',
        ])->getContent();
    }

    private function outsideCall(Tenant $tenant, ?int $seconds, ?\DateTimeInterface $at = null): void
    {
        $this->in($tenant, function () use ($seconds, $at) {
            $call = Call::create(['carrier_sid' => self::callId($this->n++), 'direction' => 'outbound', 'decision' => 'number',
                'to' => '+447700900123', 'status' => $seconds === null ? 'initiated' : 'completed', 'duration_seconds' => $seconds]);
            if ($at) {
                $call->forceFill(['created_at' => $at])->save();
            }
        });
    }

    public function test_the_plan_set_by_the_operator_is_the_companys_licence(): void
    {
        $this->acme->forceFill(['plan' => Plan::Pro, 'seats' => 3])->save();

        $licence = $this->in($this->acme->fresh(), fn () => app(LicenceManager::class)->current());
        $this->assertSame(Plan::Pro, $licence->plan);
        $this->assertSame(3, $licence->maxUsers());
        $this->assertTrue($licence->allows(Feature::AiSentiment));
        $this->assertFalse($this->in($this->bolt, fn () => app(LicenceManager::class)->allows(Feature::AiSentiment)));

        // A licence key changes nothing, and there's nowhere to enter one.
        $this->actingAs($this->owner($this->acme))->withSession(['auth.password_confirmed_at' => time()]);
        $this->get('http://acme.quaverdesk.test/admin/licence')->assertOk()->assertSee('Pro')->assertSee('set by your QuaverDesk provider')->assertDontSee('Licence key');
        $this->put('http://acme.quaverdesk.test/admin/licence', ['key' => 'qdl1.anything'])->assertNotFound();
    }

    public function test_seats_and_numbers_stop_at_the_plan(): void
    {
        $this->acme->forceFill(['seats' => 1, 'max_numbers' => 1])->save();
        $this->actingAs($this->owner($this->acme))->withSession(['auth.password_confirmed_at' => time()]);

        $this->post('http://acme.quaverdesk.test/admin/users', ['name' => 'Two', 'email' => 'two@acme.test', 'role' => 'user'])
            ->assertSessionHasErrors('email');
        $trunk = $this->in($this->acme, fn () => SipTrunk::query()->first());
        $this->post('http://acme.quaverdesk.test/admin/numbers', ['e164' => '020 7946 0999', 'sip_trunk_id' => $trunk->id])
            ->assertSessionHasErrors(['e164' => 'Your plan includes 1 phone numbers. Ask your provider for more.']);

        $this->acme->forceFill(['max_numbers' => 2])->save();
        $this->post('http://acme.quaverdesk.test/admin/numbers', ['e164' => '020 7946 0999', 'sip_trunk_id' => $trunk->id])->assertSessionHasNoErrors();
    }

    public function test_monthly_minutes_and_lines_come_from_the_plan(): void
    {
        $this->acme->forceFill(['monthly_minutes' => 10, 'concurrent_calls' => 1])->save();
        $this->assertStringContainsString('bridge', $this->dial($this->acme));

        // That call is still going, and the plan has one line, whatever the company's own setting says.
        $this->in($this->acme, fn () => Setting::put('calling.limits', ['concurrent' => 50]));
        $this->assertStringContainsString('outside lines are in use', $this->dial($this->acme));
        $this->in($this->acme, fn () => Call::query()->update(['duration_seconds' => 60, 'status' => 'completed']));

        // Calls are cut off when the month's minutes run out (9 left of 10)…
        $this->assertStringContainsString("sched_hangup +540 ALLOTTED_TIMEOUT", $this->dial($this->acme));
        $this->in($this->acme, fn () => Call::query()->update(['duration_seconds' => 300, 'status' => 'completed']));

        // …and refused once they have; last month's calls don't count, nor do another company's.
        $this->assertStringContainsString("used this month's 10 minutes", $this->dial($this->acme));
        $this->in($this->acme, fn () => Call::query()->update(['created_at' => now()->subMonthsNoOverflow(2)]));
        $this->assertStringContainsString('bridge', $this->dial($this->acme));
        $this->assertStringContainsString('bridge', $this->dial($this->bolt));

        // Incoming calls use the same minutes (a number that isn't in service doesn't).
        $this->in($this->acme, fn () => Call::query()->update(['status' => 'completed', 'duration_seconds' => 60, 'created_at' => now()->subMonthsNoOverflow(2)]));
        $incoming = fn (string $decision) => $this->in($this->acme, fn () => Call::query()->create([
            'carrier_sid' => (string) Str::uuid(), 'direction' => 'inbound', 'from' => '+447700900123', 'to' => '+442079460000',
            'decision' => $decision, 'status' => 'completed', 'duration_seconds' => 600,
        ]));
        $incoming('reject');
        $this->assertStringContainsString('bridge', $this->dial($this->acme));
        $this->in($this->acme, fn () => Call::query()->where('direction', 'outbound')->update(['status' => 'completed', 'duration_seconds' => 0]));
        $incoming('ring_group');
        $this->assertStringContainsString("used this month's 10 minutes", $this->dial($this->acme));
    }

    public function test_the_whole_service_has_its_own_caps(): void
    {
        config(['tenancy.max_concurrent_calls' => 2, 'tenancy.max_minutes_per_day' => 30]);
        $this->outsideCall($this->acme, null);
        $this->outsideCall($this->bolt, null);

        // Each company is well within its plan, but together they're at the service's cap.
        $this->assertStringContainsString('unavailable right now', $this->dial($this->bolt));

        $this->tenancy->everywhere(fn () => Call::query()->update(['duration_seconds' => 900, 'status' => 'completed']));
        $this->assertStringContainsString('unavailable right now', $this->dial($this->acme)); // 30 minutes today between them
        $this->tenancy->everywhere(fn () => Call::query()->update(['created_at' => now()->subDays(2)]));
        $this->assertStringContainsString('bridge', $this->dial($this->acme));
    }

    public function test_calls_stop_being_recorded_when_storage_is_full(): void
    {
        $this->acme->forceFill(['storage_mb' => 1])->save();
        $policy = fn () => $this->in($this->acme->fresh(), fn () => app(RecordingPolicy::class)->canRecordCalls());
        $this->assertTrue($policy());

        $this->in($this->acme, fn () => Recording::create(['kind' => 'call', 'carrier_sid' => self::callId(1).'-r', 'status' => 'stored'])
            ->forceFill(['bytes' => 1024 * 1024 + 1])->save());
        $this->assertFalse($policy());
        $this->assertTrue($this->in($this->bolt, fn () => app(RecordingPolicy::class)->canRecordCalls()));
    }

    public function test_slow_jobs_take_turns_between_companies(): void
    {
        config(['tenancy.jobs_per_minute' => 2]);
        $job = new class
        {
            public ?int $released = null;

            public function release(int $delay): void
            {
                $this->released = $delay;
            }
        };
        $ran = 0;
        $run = function (Tenant $tenant) use ($job, &$ran) {
            $this->in($tenant, function () use ($job, &$ran) {
                (new TakeTurns())->handle($job, function () use (&$ran) {
                    $ran++;
                });
            });
        };

        $run($this->acme);
        $run($this->acme);
        $this->assertNull($job->released);
        $run($this->acme); // Acme's third this minute goes to the back of the queue
        $this->assertSame(2, $ran);
        $this->assertGreaterThan(0, $job->released);

        $run($this->bolt); // Bolt isn't held up by Acme's backlog
        $this->assertSame(3, $ran);
    }

    public function test_one_companys_call_flood_is_turned_away_without_affecting_others(): void
    {
        config(['tenancy.webhooks_per_minute' => 2]);
        $this->dial($this->acme);
        $this->dial($this->acme);

        $this->voicePost('/webhooks/voice/outgoing', ['session_id' => self::callId(900), 'from' => $this->owner($this->acme)->carrierIdentity(),
            'Caller-Destination-Number' => '07700900123'])->assertTooManyRequests();
        $this->assertStringContainsString('bridge', $this->dial($this->bolt));
    }

    public function test_the_operator_sets_a_companys_plan(): void
    {
        $operator = Operator::query()->create(['name' => 'Olly', 'email' => 'olly@quaverdesk.test', 'password' => 'a-long-operator-password-1']);
        $operator->forceFill(['two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now()])->save();
        $this->actingAs($operator, 'operator');
        $console = 'http://operator.quaverdesk.test/operator/companies/'.$this->acme->id;

        $this->get($console)->assertOk()->assertSee('Save plan')->assertSee('0 of 1,000 minutes');
        $this->put($console.'/plan', ['plan' => 'pro', 'seats' => 0, 'max_numbers' => 3, 'monthly_minutes' => 500, 'concurrent_calls' => 2, 'storage_mb' => 2048])
            ->assertSessionHasErrors('seats');
        $this->put($console.'/plan', ['plan' => 'pro', 'seats' => 12, 'max_numbers' => 3, 'monthly_minutes' => 500, 'concurrent_calls' => 2, 'storage_mb' => 2048])
            ->assertSessionHasNoErrors();

        $acme = $this->acme->fresh();
        $this->assertSame([Plan::Pro, 12, 3, 500, 2, 2048], array_values($acme->only(Tenant::PLAN_FIELDS)));
        $this->assertDatabaseHas('operator_audit', ['action' => 'company.plan', 'tenant_id' => $acme->id]);

        $this->artisan('tenant:plan', ['slug' => 'bolt', '--minutes' => '-5'])->assertExitCode(2);
        $this->artisan('tenant:plan', ['slug' => 'bolt', '--plan' => 'free', '--minutes' => '0'])->assertSuccessful();
        $this->assertSame([Plan::Free, 0], [$this->bolt->fresh()->plan, $this->bolt->fresh()->monthly_minutes]);
    }
}
