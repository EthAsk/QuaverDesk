<?php

namespace Tests\Feature;

use App\Models\Call;
use App\Models\PhoneNumber;
use App\Models\Setting;
use App\Models\SipTrunk;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CallCharges;
use App\Tenancy\CallRates;
use App\Tenancy\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Included minutes cover UK calls; UK calls past them and every other number are charged per minute. */
class CallChargesTest extends TestCase
{
    use RefreshDatabase;

    private Tenancy $tenancy;
    private Tenant $acme;
    private int $n = 100;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['tenancy.enabled' => true, 'tenancy.base_domain' => 'quaverdesk.test', 'tenancy.operator_host' => 'operator.quaverdesk.test',
            'tenancy.call_charges.enabled' => true, 'tenancy.call_charges.overage_percent' => 100, 'tenancy.call_charges.other_pence' => [],
            'tenancy.call_charges.markup' => 1.5, 'tenancy.call_charges.usd_to_gbp' => 0.76, 'tenancy.call_charges.inbound_usd' => 0.006,
            'tenancy.call_charges.rates_file' => base_path('tests/fixtures/call-rates.php')]);
        CallRates::flush();
        $this->tenancy = app(Tenancy::class);
        $this->acme = Tenant::query()->create(['name' => 'Acme', 'slug' => 'acme']);
        $this->acme->forceFill(['monthly_minutes' => 10])->save();
        $this->tenancy->run($this->acme, function () {
            $this->acknowledgeTerms(User::factory()->owner()->withTwoFactor()->create());
            $trunk = SipTrunk::create(['name' => 'Trunk', 'provider' => 'other', 'inbound_ips' => [['ip' => '203.0.113.10', 'prefix' => 32]],
                'origination_uris' => ['sip:sbc.example.net'], 'status' => 'active', 'default_outbound' => true]);
            $trunk->forceFill(['supplied_by_operator' => true])->save(); // ours: its calls use minutes and are charged
            PhoneNumber::create(['e164' => '+442079460000', 'sip_trunk_id' => $trunk->id, 'destination' => 'hangup']);
            Setting::put('calling.caller_id', '+442079460000');
            Setting::put('calling.international_prefixes', ['+33', '+34']);
            Setting::put('calling.premium_allowed', true);
        });
        $this->tenancy->set(null);
    }

    private function dial(string $number): string
    {
        $owner = $this->tenancy->run($this->acme, fn () => User::query()->where('role', User::ROLE_OWNER)->first());

        return $this->voicePost('/webhooks/voice/outgoing', [
            'session_id' => self::callId($this->n++), 'from' => $owner->carrierIdentity(), 'Caller-Destination-Number' => $number,
        ])->getContent();
    }

    private function made(string $direction, string $to, int $seconds, int $minutesAgo = 0): void
    {
        $this->tenancy->run($this->acme, fn () => Call::query()->create([
            'carrier_sid' => (string) Str::uuid(), 'direction' => $direction, 'from' => '+442079460000', 'to' => $to, 'sip_trunk_id' => SipTrunk::query()->value('id'),
            'decision' => $direction === 'inbound' ? 'ring_group' : 'number', 'status' => 'completed', 'duration_seconds' => $seconds,
        ])->forceFill(['created_at' => now()->subMinutes($minutesAgo)])->save());
    }

    public function test_numbers_are_sorted_into_what_the_minutes_cover(): void
    {
        $this->assertSame('inbound', CallCharges::kind('inbound', '+442079460000'));
        foreach (['+441142000000', '+442079460000', '+443001234567', '+448001234567'] as $n) {
            $this->assertSame('landline', CallCharges::kind('outbound', $n), $n);
        }
        $this->assertSame('mobile', CallCharges::kind('outbound', '+447700900123'));
        foreach (['+447010000000', '+447600000000', '+448441234567', '+448701234567', '+449001234567', '+33123456789'] as $n) {
            $this->assertSame('other', CallCharges::kind('outbound', $n), $n);
        }

        // While charges are off, every call counts towards the minutes as before.
        config(['tenancy.call_charges.enabled' => false]);
        $this->assertTrue(CallCharges::counts('other'));
        $this->assertNull(CallCharges::refusal('+33123456789'));
        $this->assertSame(0, CallCharges::overageSeconds(1000));
    }

    public function test_uk_calls_carry_on_past_the_minutes_up_to_the_overage(): void
    {
        $this->made('outbound', '+447700900123', 600);
        // An international call doesn't use the UK minutes.
        config(['tenancy.call_charges.other_pence' => ['+33' => 10]]);
        $this->made('outbound', '+33123456789', 600);

        // 10 of 10 minutes used: another 10 (100%) are allowed and charged.
        $this->assertStringContainsString('sched_hangup +600 ALLOTTED_TIMEOUT', $this->dial('07700900123'));
        $this->tenancy->run($this->acme, fn () => Call::query()->whereNull('duration_seconds')->update(['status' => 'completed', 'duration_seconds' => 600]));
        $this->assertStringContainsString('minutes of UK calls', $this->dial('07700900123'));

        // Calls that don't use the minutes still go through.
        $this->assertStringContainsString('bridge', $this->dial('0033123456789'));
    }

    public function test_every_destination_is_priced_from_the_rate_card_at_one_and_a_half_times_cost(): void
    {
        // 0.0147 USD at 0.76 GBP/USD is 1.1172p; x1.5 is 1.6758p. Mobiles in France cost more, and the longest prefix wins.
        $this->assertEqualsWithDelta(1.6758, CallRates::pence('+33123456789'), 0.0001);
        $this->assertEqualsWithDelta(17.8182, CallRates::pence('+33612345678'), 0.0001);
        $this->assertEqualsWithDelta(1.3452, CallRates::pence('+441142000000'), 0.0001);
        $this->assertEqualsWithDelta(3.021, CallRates::pence('+447511123456'), 0.0001);
        $this->assertEqualsWithDelta(0.684, CallRates::inboundPence(), 0.0001);
        $this->assertSame(['£1.19', '17.8p', '1.35p'], [CallRates::format(118.9), CallRates::format(17.8182), CallRates::format(1.3452)]);

        // The markup and exchange rate are settings.
        config(['tenancy.call_charges.markup' => 2.0, 'tenancy.call_charges.usd_to_gbp' => 0.8]);
        $this->assertEqualsWithDelta(2.352, CallRates::pence('+33123456789'), 0.0001);

        // Our own rates win when they're at least as specific.
        config(['tenancy.call_charges.other_pence' => ['+33' => 10, '+33612' => 20]]);
        $this->assertSame(20.0, CallRates::pence('+33612345678'));
        $this->assertSame(10.0, CallRates::pence('+33123456789'));
        $this->assertSame('+33', CallRates::lookup('+33123456789')['prefix']);
    }

    public function test_numbers_without_a_rate_are_refused_and_priced_ones_go_through(): void
    {
        // Spain isn't in the rate card (the company allows it, so it's only our price that's missing).
        $this->assertStringContainsString("aren't available on your plan", $this->dial('0034912345678'));
        $this->assertStringContainsString('bridge', $this->dial('0033123456789'));
        // Premium numbers are priced like anything else (the company has allowed them).
        $this->assertStringContainsString('bridge', $this->dial('09001234567'));

        config(['tenancy.call_charges.other_pence' => ['+34' => 12]]);
        $this->assertStringContainsString('bridge', $this->dial('0034912345678'));
    }

    public function test_calls_on_the_companys_own_trunk_use_no_minutes_and_cost_nothing(): void
    {
        $this->made('outbound', '+447700900123', 1200); // 10 minutes and the 10 extra used, on our trunk
        $this->assertStringContainsString('minutes of UK calls', $this->dial('07700900123'));

        // Their own trunk: nothing counted, no monthly limit, and any number they allow can be called.
        $this->tenancy->run($this->acme, fn () => SipTrunk::query()->update(['supplied_by_operator' => false]));
        $this->assertSame(0, CallCharges::forCompany($this->acme)['total_pence']);
        $this->assertStringContainsString('bridge', $xml = $this->dial('07700900123'));
        $this->assertStringNotContainsString('sched_hangup +6', $xml);
        $this->assertStringContainsString('bridge', $this->dial('0033123456789'));
    }

    public function test_the_months_charges_are_worked_out_in_call_order(): void
    {
        $this->made('inbound', '+442079460000', 300, 40);   // 5 included
        $this->made('outbound', '+447511123456', 600, 30);  // 5 included, 5 extra at 3.02p
        $this->made('outbound', '+441142000000', 120, 20);  // 2 extra at 1.35p
        $this->made('outbound', '+33123456789', 90, 10);    // 2 (rounded up) at 1.68p, never using the minutes
        $this->tenancy->run($this->acme, fn () => Call::query()->create(['carrier_sid' => (string) Str::uuid(), 'direction' => 'outbound',
            'to' => '+447511123456', 'sip_trunk_id' => SipTrunk::query()->value('id'), 'decision' => 'number', 'status' => 'completed', 'duration_seconds' => 6000])
            ->forceFill(['created_at' => now()->subMonthsNoOverflow(2)])->save()); // another month

        $usage = CallCharges::forCompany($this->acme);
        $this->assertSame(10, $usage['included_minutes']);
        $this->assertSame(['minutes' => 5, 'pence_per_minute' => 3.2, 'pence' => 16], $usage['extra']['mobile']);
        $this->assertSame(['minutes' => 2, 'pence_per_minute' => 1.5, 'pence' => 3], $usage['extra']['landline']);
        $this->assertSame(2, $usage['other']['+33']['minutes']);
        $this->assertEqualsWithDelta(1.6758, $usage['other']['+33']['pence_per_minute'], 0.0001);
        $this->assertSame(4, $usage['other']['+33']['pence']);
        $this->assertSame(23, $usage['total_pence']);

        // The company sees them too.
        $this->actingAs($this->tenancy->run($this->acme, fn () => User::query()->where('role', User::ROLE_OWNER)->first()))
            ->withSession(['auth.password_confirmed_at' => time()]);
        $this->get('http://acme.quaverdesk.test/admin/licence')->assertOk()->assertSee('Calls charged on top this month')
            ->assertSee('Extra minutes to UK mobiles')->assertSee('£0.16');

        // And the operator's report for direct customers.
        $this->artisan('hosted:call-charges', ['--month' => CarbonImmutable::now('Europe/London')->format('Y-m')])->expectsOutputToContain('Total: £0.23')->assertSuccessful();
    }
}
