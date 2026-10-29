<?php

namespace Tests\Feature;

use App\Domain\Licensing\LicenceCodec;
use App\Domain\Licensing\Plan;
use App\Models\Call;
use App\Models\PhoneNumber;
use App\Models\Setting;
use App\Models\SipTrunk;
use App\Models\Sound;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\Tenancy;
use App\Voice\ParkingLot;
use App\Voice\VoiceServer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/** The hosted service's calls: each one belongs to exactly one company, decided by the voice server's facts. */
class VoiceTenancyTest extends TestCase
{
    use RefreshDatabase;

    private Tenancy $tenancy;
    private Tenant $acme;
    private Tenant $bolt;

    protected function setUp(): void
    {
        parent::setUp();
        config(['tenancy.enabled' => true, 'tenancy.base_domain' => 'quaverdesk.test', 'voip.caller_id' => '+441110000000']);
        $this->tenancy = app(Tenancy::class);
        $this->acme = Tenant::query()->create(['name' => 'Acme', 'slug' => 'acme']);
        $this->bolt = Tenant::query()->create(['name' => 'Bolt', 'slug' => 'bolt']);

        // One signing key, a Plus licence for each company.
        $keys = LicenceCodec::generateKeyPair();
        config(['voip.licence_public_key' => $keys['public']]);
        foreach ([$this->acme, $this->bolt] as $tenant) {
            $this->in($tenant, function () use ($keys) {
                Setting::put('licence_key', LicenceCodec::issue($keys['secret'], 'Test Ltd', Plan::Plus, 25, null));
                $this->acknowledgeTerms(User::factory()->owner()->create());
            });
        }
        $this->tenancy->set(null);
    }

    private function in(Tenant $tenant, \Closure $callback): mixed
    {
        return $this->tenancy->run($tenant, $callback);
    }

    /** A trunk at $ip with $number on it, in $tenant. */
    private function numberOnTrunk(Tenant $tenant, string $number, string $ip, string $destination): PhoneNumber
    {
        return $this->in($tenant, function () use ($number, $ip, $destination) {
            $trunk = SipTrunk::create([
                'name' => 'Trunk', 'provider' => 'other', 'inbound_ips' => [['ip' => $ip, 'prefix' => 32]],
                'origination_uris' => ['sip:sbc.example.net'], 'status' => 'active', 'default_outbound' => true,
            ]);

            return PhoneNumber::create(['e164' => $number, 'sip_trunk_id' => $trunk->id, 'destination' => $destination]);
        });
    }

    private function callsIn(Tenant $tenant): array
    {
        return $this->in($tenant, fn () => Call::query()->pluck('carrier_sid')->all());
    }

    public function test_extensions_are_dialled_within_the_callers_company(): void
    {
        [$acmeOwner, $alice] = $this->in($this->acme, fn () => [User::query()->first(), User::factory()->create()]);
        [$boltOwner, $bob] = $this->in($this->bolt, fn () => [User::query()->first(), User::factory()->create()]);
        $this->assertSame(['202', '202'], [$alice->extension, $bob->extension]);

        $xml = $this->voicePost('/webhooks/voice/outgoing', ['session_id' => self::callId(1), 'from' => 'user-'.$acmeOwner->id, 'Caller-Destination-Number' => '202'])->getContent();
        $this->assertStringContainsString("']user/user-{$alice->id}@voice.test\"", $xml);

        $xml = $this->voicePost('/webhooks/voice/outgoing', ['session_id' => self::callId(2), 'from' => 'user-'.$boltOwner->id, 'Caller-Destination-Number' => '202'])->getContent();
        $this->assertStringContainsString("']user/user-{$bob->id}@voice.test\"", $xml);

        $this->assertSame([self::callId(1)], $this->callsIn($this->acme));
        $this->assertSame([self::callId(2)], $this->callsIn($this->bolt));
    }

    public function test_a_phone_cannot_ring_someone_at_another_company_by_their_identity(): void
    {
        $acmeOwner = $this->in($this->acme, fn () => User::query()->first());
        [$boltOwner, $bob] = $this->in($this->bolt, fn () => [User::query()->first(), User::factory()->create()]);

        foreach (["user-{$bob->id}", "*{$bob->id}", "client:user-{$bob->id}"] as $i => $dialled) {
            $xml = $this->voicePost('/webhooks/voice/outgoing', ['session_id' => self::callId(50 + $i), 'from' => 'user-'.$acmeOwner->id, 'Caller-Destination-Number' => $dialled])->getContent();
            $this->assertStringNotContainsString('bridge', $xml, "Acme reached Bolt by dialling {$dialled}");
            $this->assertStringContainsString('not in use', $xml);
        }

        // Within the company it still works.
        $xml = $this->voicePost('/webhooks/voice/outgoing', ['session_id' => self::callId(60), 'from' => 'user-'.$boltOwner->id, 'Caller-Destination-Number' => "*{$bob->id}"])->getContent();
        $this->assertStringContainsString("']user/user-{$bob->id}@voice.test\"", $xml);
    }

    public function test_a_suspended_company_can_still_call_999_and_112_and_nothing_else(): void
    {
        $owner = $this->in($this->bolt, fn () => User::query()->first());
        $this->numberOnTrunk($this->bolt, '+442079460000', '203.0.113.20', "user:{$owner->id}");
        $this->in($this->bolt, fn () => Setting::put('calling.caller_id', '+442079460000'));
        $this->bolt->forceFill(['status' => Tenant::STATUS_SUSPENDED])->save();
        $dial = fn (int $n, string $to) => $this->voicePost('/webhooks/voice/outgoing', ['session_id' => self::callId($n), 'from' => 'user-'.$owner->id, 'Caller-Destination-Number' => $to])->getContent();

        $this->assertStringContainsString('bridge', $dial(70, '999'));
        $this->assertStringContainsString('bridge', $dial(71, '112'));
        foreach (['07700900123', '202'] as $i => $to) {
            $this->assertStringNotContainsString('bridge', $dial(72 + $i, $to), "A suspended company called {$to}");
        }
        // The emergency call's later steps still reach it; incoming calls don't.
        $this->voicePost('/webhooks/voice/after-dial?next=hangup', ['session_id' => self::callId(70), 'originate_disposition' => 'SUCCESS'])->assertSee('<hangup/>', false);
        $this->assertStringContainsString('not in service', $this->voicePost('/webhooks/voice/inbound', [
            'session_id' => self::callId(75), 'Caller-Destination-Number' => '+442079460000', 'source' => '203.0.113.20',
        ])->getContent());
        $this->assertSame([self::callId(70), self::callId(71)], $this->in($this->bolt, fn () => Call::query()->orderBy('id')->pluck('carrier_sid')->all()));
    }

    public function test_a_transferred_call_stays_with_its_company(): void
    {
        [$acmeOwner, $alice] = $this->in($this->acme, fn () => [User::query()->first(), User::factory()->create()]);
        [$boltOwner, $bob] = $this->in($this->bolt, fn () => [User::query()->first(), User::factory()->create()]);
        $this->voicePost('/webhooks/voice/outgoing', ['session_id' => self::callId(1), 'from' => 'user-'.$acmeOwner->id, 'Caller-Destination-Number' => '202']);
        $transfer = fn (int $leg, string $by, string $to) => $this->voicePost('/webhooks/voice/transfer', [
            'session_id' => self::callId($leg), 'call' => self::callId(1), 'by' => $by, 'Caller-Destination-Number' => $to,
        ])->getContent();

        // The other side of Acme's call (a leg with no log of its own) is Acme's: extension 202 is Alice, not Bob.
        $this->assertStringContainsString("user/user-{$alice->id}@voice.test", $transfer(2, 'user-'.$acmeOwner->id, '202'));
        $this->assertStringNotContainsString("user-{$bob->id}@", $transfer(3, 'user-'.$acmeOwner->id, "user-{$bob->id}"));
        // A Bolt phone named as the transferrer of Acme's call isn't found there.
        $this->assertStringContainsString('cannot be transferred', $transfer(4, 'user-'.$boltOwner->id, '202'));
        // Its later steps and parking stay with Acme.
        $this->assertSame([], $this->callsIn($this->bolt));
        $this->assertSame(2, $this->in($this->acme, fn () => Call::query()->where('leg', Call::LEG_TRANSFER)->count())); // the refused one isn't logged
        $this->voicePost('/webhooks/voice/after-dial?next=hangup', ['session_id' => self::callId(2), 'exiting' => 'true'])->assertNoContent();
        $this->assertNotNull($this->in($this->acme, fn () => Call::query()->where('parent_sid', self::callId(2))->value('duration_seconds')));
        $this->assertSame('qd-park-'.$this->acme->id, $this->in($this->acme, fn () => app(ParkingLot::class)->name()));
    }

    public function test_unknown_phones_are_turned_away_and_nothing_is_stored(): void
    {
        $xml = $this->voicePost('/webhooks/voice/outgoing', ['session_id' => self::callId(3), 'from' => 'user-99999', 'Caller-Destination-Number' => '201'])->getContent();

        $this->assertStringContainsString('not in service', $xml);
        $this->assertStringNotContainsString('bridge', $xml);
        $this->assertSame(0, $this->tenancy->everywhere(fn () => Call::query()->count()));
    }

    public function test_trunk_calls_ring_the_company_that_owns_the_number(): void
    {
        $owner = $this->in($this->bolt, fn () => User::query()->first());
        $this->numberOnTrunk($this->bolt, '+442079460000', '203.0.113.20', "user:{$owner->id}");

        $xml = $this->voicePost('/webhooks/voice/inbound', [
            'session_id' => self::callId(4), 'Caller-Caller-ID-Number' => '+447700900123',
            'Caller-Destination-Number' => '+442079460000', 'source' => '203.0.113.20',
        ])->getContent();

        $this->assertStringContainsString("']user/user-{$owner->id}@voice.test\"", $xml);
        $this->assertSame([self::callId(4)], $this->callsIn($this->bolt));
    }

    public function test_the_voice_server_is_called_back_on_the_app_address_not_the_companys(): void
    {
        config(['voip.freeswitch.app_url' => 'http://127.0.0.1:8080']);
        $owner = $this->in($this->bolt, fn () => User::query()->first());
        $this->numberOnTrunk($this->bolt, '+442079460000', '203.0.113.20', "user:{$owner->id}");

        $xml = $this->voicePost('/webhooks/voice/inbound', [
            'session_id' => self::callId(5), 'Caller-Caller-ID-Number' => '+447700900123',
            'Caller-Destination-Number' => '+442079460000', 'source' => '203.0.113.20',
        ])->getContent();

        // The voice server may not be able to reach a company's own address from where it sits.
        $this->assertStringContainsString('http://127.0.0.1:8080/webhooks/voice/after-dial', $xml);
        $this->assertStringNotContainsString('bolt.quaverdesk.test', $xml);
    }

    public function test_a_company_cannot_send_calls_to_another_companys_number_from_its_own_trunk(): void
    {
        $owner = $this->in($this->bolt, fn () => User::query()->first());
        $this->numberOnTrunk($this->bolt, '+442079460000', '203.0.113.20', "user:{$owner->id}");
        // Acme adds a "trunk" at a server it controls, and uses it to call Bolt's number.
        $this->numberOnTrunk($this->acme, '+442079461111', '198.51.100.66', 'hangup');

        foreach (['198.51.100.66', '', 'not-an-ip'] as $i => $source) {
            $xml = $this->voicePost('/webhooks/voice/inbound', [
                'session_id' => self::callId(10 + $i), 'Caller-Destination-Number' => '+442079460000', 'source' => $source,
            ])->getContent();

            $this->assertStringContainsString('not in service', $xml);
            $this->assertStringNotContainsString('bridge', $xml);
        }
        $this->assertSame(0, $this->tenancy->everywhere(fn () => Call::query()->count()));
    }

    public function test_on_a_shared_provider_a_trunk_call_must_arrive_at_the_trunks_own_registration(): void
    {
        $owner = $this->in($this->bolt, fn () => User::query()->first());
        $number = $this->numberOnTrunk($this->bolt, '+442079460000', '203.0.113.20', "user:{$owner->id}");
        $trunk = $this->in($this->bolt, fn () => $number->trunk);
        $call = fn (int $n, string $gateway = '') => $this->voicePost('/webhooks/voice/inbound', [
            'session_id' => self::callId($n), 'Caller-Destination-Number' => '+442079460000', 'source' => '203.0.113.20', 'gateway' => $gateway,
        ])->getContent();

        // Nobody else uses that provider: its address is enough, as before.
        $this->assertStringContainsString('bridge', $call(40));

        // Acme signs up with the same provider, so the address no longer says whose trunk it is.
        $this->numberOnTrunk($this->acme, '+442079461111', '203.0.113.20', 'hangup');
        $this->assertStringContainsString('not in service', $call(41));

        // Bolt's trunk registers: calls to its own registration (gw+<name>, which ends in a secret) are Bolt's.
        $this->in($this->bolt, fn () => $trunk->update(['auth_username' => 'bolt', 'auth_password' => 'secret-1', 'register' => true]));
        $gateway = $trunk->gatewayNames()[0];
        $this->assertMatchesRegularExpression('/^qd-t\d+-1-[a-f0-9]{12}$/', $gateway);
        $this->assertStringContainsString("']user/user-{$owner->id}@voice.test\"", $call(42, $gateway));
        $this->assertStringContainsString('not in service', $call(43));
        $this->assertStringContainsString('not in service', $call(44, preg_replace('/[a-f0-9]{12}$/', str_repeat('0', 12), $gateway)));
        $acmeGateway = $this->in($this->acme, fn () => SipTrunk::query()->first()->gatewayNames()[0]);
        $this->assertStringContainsString('not in service', $call(45, $acmeGateway));

        // Trunks we supply come from our own carrier, which only sends us our numbers.
        $trunk->forceFill(['supplied_by_operator' => true, 'register' => false])->save();
        $this->assertStringContainsString('bridge', $call(46));
        $this->assertSame([self::callId(40), self::callId(42), self::callId(46)], $this->callsIn($this->bolt));
    }

    public function test_later_steps_of_a_call_stay_with_its_company(): void
    {
        $this->in($this->bolt, fn () => Call::create(['carrier_sid' => self::callId(20), 'direction' => 'internal', 'status' => 'initiated']));

        $this->voicePost('/webhooks/voice/after-dial?next=hangup', ['session_id' => self::callId(20), 'originate_disposition' => 'NO_ANSWER'])
            ->assertSee('<hangup/>', false);
        $this->assertSame('no-answer', $this->in($this->bolt, fn () => Call::query()->first()->status));

        // A call id no company has is turned away; its end is simply acknowledged.
        $this->voicePost('/webhooks/voice/after-dial?next=hangup', ['session_id' => self::callId(21)])->assertSee('not in service');
        $this->voicePost('/webhooks/voice/after-dial?next=hangup', ['session_id' => self::callId(21), 'exiting' => 'true'])->assertNoContent();
    }

    public function test_hosted_companies_never_show_the_operators_caller_id(): void
    {
        $owner = $this->in($this->bolt, fn () => User::query()->first());
        $this->numberOnTrunk($this->bolt, '+442079460000', '203.0.113.20', 'hangup');

        $xml = $this->voicePost('/webhooks/voice/outgoing', ['session_id' => self::callId(30), 'from' => 'user-'.$owner->id, 'Caller-Destination-Number' => '07700900123'])->getContent();
        $this->assertStringNotContainsString('+441110000000', $xml);
        $this->assertStringNotContainsString('bridge', $xml);

        $this->in($this->bolt, fn () => Setting::put('calling.caller_id', '+442079460000'));
        $xml = $this->voicePost('/webhooks/voice/outgoing', ['session_id' => self::callId(31), 'from' => 'user-'.$owner->id, 'Caller-Destination-Number' => '07700900123'])->getContent();
        $this->assertStringContainsString('+442079460000', $xml);
        $this->assertStringContainsString('bridge', $xml);
    }

    public function test_every_companys_phones_get_their_own_hold_music(): void
    {
        $dir = storage_path('framework/testing/freeswitch-'.bin2hex(random_bytes(4)));
        config(['voip.freeswitch.conf_dir' => $dir, 'voip.freeswitch.esl_password' => null]);
        [$acmeOwner, $music] = $this->in($this->acme, function () {
            $sound = Sound::query()->create(['name' => 'Jazz', 'file' => str_repeat('a', 40), 'seconds' => 30]);
            Setting::put('sounds.hold_music', $sound->id);

            return [User::query()->first(), $sound];
        });
        $boltOwner = $this->in($this->bolt, fn () => User::query()->first());

        try {
            app(VoiceServer::class)->sync();
            $users = file_get_contents($dir.'/directory/users.xml');
            $vars = file_get_contents($dir.'/vars/quaverdesk.xml');
        } finally {
            File::deleteDirectory($dir);
        }

        $this->assertMatchesRegularExpression('#<user id="user-'.$acmeOwner->id.'">.*name="hold_music" value="'.preg_quote($music->url(), '#').'"#', $users);
        $this->assertMatchesRegularExpression('#<user id="user-'.$boltOwner->id.'">(?:(?!</user>).)*</user>#', $users);
        $this->assertDoesNotMatchRegularExpression('#<user id="user-'.$boltOwner->id.'">(?:(?!</user>).)*hold_music#', $users);
        $this->assertStringContainsString('hold_music=silence', $vars);
    }

    public function test_the_voice_server_fetches_sounds_from_the_app_address(): void
    {
        File::ensureDirectoryExists(dirname(Sound::pathFor(str_repeat('c', 40))));
        file_put_contents(Sound::pathFor(str_repeat('c', 40)), self::wav(1));

        try {
            $this->get('http://app.quaverdesk.test/voice/sounds/'.str_repeat('c', 40).'.wav')->assertOk();
        } finally {
            @unlink(Sound::pathFor(str_repeat('c', 40)));
        }
    }
}
