<?php

namespace Tests\Feature;

use App\Domain\Licensing\Plan;
use App\Models\DeskPhone;
use App\Models\DeskPhoneEvent;
use App\Models\User;
use App\Voice\FreeSwitch\FreeSwitchServer;
use App\Voice\PhoneProvisioning;
use App\Voice\VoiceServer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Desk phones that set themselves up: Yealink, Grandstream and Fanvil. */
class PhoneProvisioningTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private FreeSwitchServer $voice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->usePlan(Plan::Pro);
        $this->owner = User::factory()->owner()->withTwoFactor()->create(['name' => 'Alex Owner']);
        $this->voice = new class(config('voip.freeswitch')) extends FreeSwitchServer
        {
            public array $resynced = [];

            public function resyncPhone(string $sipUsername): void
            {
                $this->resynced[] = $sipUsername;
            }
        };
        $this->app->instance(VoiceServer::class, $this->voice);
    }

    private function admin()
    {
        return $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => time()]);
    }

    private function addPhone(string $brand, string $mac, string $label = 'Reception'): DeskPhone
    {
        $this->admin()->post('/admin/desk-phones', ['user_id' => $this->owner->id, 'label' => $label, 'brand' => $brand, 'mac' => $mac])
            ->assertSessionHasNoErrors();

        return DeskPhone::query()->where('label', $label)->sole();
    }

    private function fetch(string $file, ?array $login = null, string $agent = 'Yealink SIP-T54W 96.86.0.70 80:5e:c0:12:34:56')
    {
        $login ??= app(PhoneProvisioning::class)->login();
        $server = ['HTTP_USER_AGENT' => $agent];
        if ($login !== []) {
            $server += ['PHP_AUTH_USER' => $login['username'], 'PHP_AUTH_PW' => $login['password']];
        }

        return $this->call('GET', '/provision/'.app(PhoneProvisioning::class)->login()['token'].'/'.$file, [], [], [], $server);
    }

    public function test_a_yealink_downloads_its_account_and_admin_password(): void
    {
        $phone = $this->addPhone('yealink', '80:5E:C0:12:34:56');
        $this->assertSame('805ec0123456', $phone->mac);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{20}$/', $phone->admin_password);

        $this->fetch('805ec0123456.cfg', [])->assertStatus(401)->assertHeader('WWW-Authenticate'); // asked to log in first
        $cfg = $this->fetch('805EC0123456.cfg')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->getContent();

        $this->assertStringStartsWith("#!version:1.0.0.1\n", $cfg);
        foreach ([
            "account.1.user_name = {$phone->sip_username}", "account.1.password = {$phone->sip_password}",
            'account.1.sip_server.1.address = voice.test', 'account.1.sip_server.1.port = 5061',
            'account.1.sip_server.1.transport_type = 2', 'account.1.srtp_encryption = 2', 'voice_mail.number.1 = *00',
            "static.security.user_password = admin:{$phone->admin_password}", "static.security.user_password = user:{$phone->admin_password}",
            "static.security.user_password = var:{$phone->admin_password}", 'account.1.display_name = Alex Owner',
            'local_time.time_zone_name = United Kingdom(London)', 'sip.notify_reboot_enable = 0',
        ] as $line) {
            $this->assertStringContainsString($line."\n", $cfg);
        }

        $phone->refresh();
        $this->assertNotNull($phone->provisioned_at);
        $this->assertSame('Yealink SIP-T54W 96.86.0.70 80:5e:c0:12:34:56', $phone->provisioned_agent);
        $this->assertTrue(DeskPhoneEvent::query()->where('kind', DeskPhoneEvent::PROVISIONED)->where('desk_phone_id', $phone->id)->exists());

        // The model-wide file isn't served.
        $this->fetch('y000000000096.cfg')->assertNotFound();
    }

    public function test_grandstream_and_fanvil_get_their_own_formats(): void
    {
        $gs = $this->addPhone('grandstream', 'c0-74-ad-00-00-01', 'Office');
        $fanvil = $this->addPhone('fanvil', '0c383e000002', 'Kitchen');

        $xml = $this->fetch('cfgc074ad000001.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8')->getContent();
        $this->assertNotFalse(simplexml_load_string($xml));
        foreach ([
            '<item name="account.1.sip.server.1.address">voice.test:5061</item>', '<item name="account.1.sip.transport">2</item>',
            '<item name="account.1.audio.srtpMode">EnabledAndForced</item>', "<item name=\"account.1.sip.subscriber.password\">{$gs->sip_password}</item>",
            "<item name=\"users.admin.password\">{$gs->admin_password}</item>", "<item name=\"users.user.password\">{$gs->admin_password}</item>", '<item name="account.1.sip.voicemail.number">*00</item>',
        ] as $item) {
            $this->assertStringContainsString($item, $xml);
        }

        // Fanvil asks for the same file name as Yealink: the phone with that MAC decides.
        $fv = $this->fetch('0c383e000002.cfg')->assertOk()->getContent();
        $this->assertNotFalse(simplexml_load_string($fv));
        foreach (['<RegisterAddr>voice.test</RegisterAddr>', '<RegisterPort>5061</RegisterPort>', '<Transport>3</Transport>',
            '<MediaCrypto>1</MediaCrypto>', "<RegisterPswd>{$fanvil->sip_password}</RegisterPswd>", "<Password>{$fanvil->admin_password}</Password>", '<MWINum>*00</MWINum>'] as $tag) {
            $this->assertStringContainsString($tag, $fv);
        }

        $this->assertSame(2, substr_count($fv, "<Password>{$fanvil->admin_password}</Password>")); // admin and guest

        // A Grandstream's MAC asked for as a .cfg isn't served as anything.
        $this->fetch('c074ad000001.cfg')->assertNotFound();
    }

    public function test_wrong_logins_unknown_phones_and_locked_phones_are_refused_and_recorded(): void
    {
        $phone = $this->addPhone('yealink', '805ec0123456');

        $this->call('GET', '/provision/'.str_repeat('0', 32).'/805ec0123456.cfg', [], [], [], ['PHP_AUTH_USER' => 'phones', 'PHP_AUTH_PW' => 'x'])->assertNotFound();
        $this->fetch('805ec0123456.cfg', ['username' => 'phones', 'password' => 'wrong'])->assertStatus(401);
        $this->fetch('805ec0999999.cfg')->assertNotFound();

        $phone->update(['allowed_ips' => '203.0.113.7/32']);
        $this->fetch('805ec0123456.cfg')->assertForbidden();
        $this->fetch('805ec0123456.cfg', agent: 'x')->assertForbidden();

        $refusals = DeskPhoneEvent::query()->where('kind', DeskPhoneEvent::REFUSED)->orderBy('id')->pluck('detail')->all();
        $this->assertStringStartsWith('Wrong provisioning login.', $refusals[0]);
        $this->assertSame('No phone is set up with MAC address 80:5E:C0:99:99:99.', $refusals[1]);
        $this->assertSame('Asked from outside the addresses the phone is locked to.', $refusals[2]);

        // From the office it works.
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7']);
        $this->fetch('805ec0123456.cfg')->assertOk();
    }

    public function test_a_locked_phone_still_fetches_from_the_local_network_unless_lan_addresses_are_filtered(): void
    {
        $phone = $this->addPhone('yealink', '805ec0123456');
        $this->admin()->patch("/admin/desk-phones/{$phone->id}/lock", ['allowed_ips' => '203.0.113.7']);
        $this->withServerVariables(['REMOTE_ADDR' => '192.168.10.65']);
        $this->fetch('805ec0123456.cfg')->assertOk();

        $this->admin()->patch("/admin/desk-phones/{$phone->id}/lock", ['allowed_ips' => '203.0.113.7', 'filter_lan' => '1']);
        $this->assertSame('Only from 203.0.113.7 (LAN addresses filtered too)', DeskPhoneEvent::query()->where('kind', DeskPhoneEvent::LOCK_CHANGED)->latest('id')->first()->detail);
        $this->fetch('805ec0123456.cfg')->assertForbidden();

        // A public address outside the lock is refused either way.
        $phone->update(['filter_lan' => false]);
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.9']);
        $this->fetch('805ec0123456.cfg')->assertForbidden();
    }

    public function test_admins_show_and_change_the_admin_password_and_every_step_is_logged(): void
    {
        $phone = $this->addPhone('yealink', '805ec0123456');
        $old = $phone->admin_password;

        $this->admin()->post("/admin/desk-phones/{$phone->id}/admin-password")->assertSessionHas('reveal_admin', $phone->id);
        $this->get('/admin/desk-phones')->assertSee($old)->assertSee('Phone log')->assertSee('Admin password shown');

        $this->post("/admin/desk-phones/{$phone->id}/admin-password/new")->assertSessionHas('status', fn ($s) => str_contains($s, 'has been told to fetch its settings'));
        $this->assertNotSame($old, $phone->fresh()->admin_password);
        $this->assertSame([$phone->sip_username], $this->voice->resynced);
        $this->assertStringContainsString('admin:'.$phone->fresh()->admin_password, $this->fetch('805ec0123456.cfg')->getContent());

        $this->post("/admin/desk-phones/{$phone->id}/send-settings")->assertSessionHasNoErrors();
        $this->post("/admin/desk-phones/{$phone->id}/reveal");

        // A new company login: the old one stops working.
        $before = app(PhoneProvisioning::class)->login();
        $this->post('/admin/desk-phones/provisioning-login');
        $this->assertNotSame($before['token'], app(PhoneProvisioning::class)->login()['token']);
        $this->call('GET', '/provision/'.$before['token'].'/805ec0123456.cfg', [], [], [], ['PHP_AUTH_USER' => 'phones', 'PHP_AUTH_PW' => $before['password']])->assertNotFound();

        $this->delete("/admin/desk-phones/{$phone->id}");

        $log = DeskPhoneEvent::query()->orderBy('id')->get();
        $this->assertSame([
            DeskPhoneEvent::ADDED, DeskPhoneEvent::ADMIN_PASSWORD_SHOWN, DeskPhoneEvent::ADMIN_PASSWORD_CHANGED, DeskPhoneEvent::RESYNC_SENT,
            DeskPhoneEvent::PROVISIONED, DeskPhoneEvent::RESYNC_SENT, DeskPhoneEvent::SETTINGS_SHOWN, DeskPhoneEvent::LOGIN_CHANGED, DeskPhoneEvent::REMOVED,
        ], $log->pluck('kind')->all());
        $this->assertSame('Reception', $log->last()->phone_label); // kept after the phone is gone
        $this->assertSame($this->owner->id, $log->first()->user_id);
    }

    public function test_busy_lamp_keys_for_each_make(): void
    {
        $sam = User::factory()->create(['name' => 'Sam Jones', 'extension' => '230']);
        $kim = User::factory()->create(['name' => 'Kim', 'extension' => '240']);
        $pat = User::factory()->forwardOnly()->create();
        $phone = $this->addPhone('yealink', '805ec0123456');

        // Never chosen: the phone's own keys are left alone.
        $this->assertStringNotContainsString('linekey.', $this->fetch('805ec0123456.cfg')->getContent());

        // Not themselves, not forward-only contacts, not people from elsewhere.
        foreach ([$this->owner->id, $pat->id, 999999] as $bad) {
            $this->admin()->put("/admin/desk-phones/{$phone->id}/keys", ['blf_keys' => [$sam->id, $bad]])->assertSessionHasErrors('blf_keys.1', null, 'keys');
        }
        $this->admin()->put("/admin/desk-phones/{$phone->id}/keys", ['blf_keys' => [$sam->id, $kim->id]])
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'busy lamp keys saved') && str_contains($s, 'told to fetch its settings'));
        $this->assertSame([$phone->sip_username], $this->voice->resynced);
        $this->assertSame('Sam Jones, Kim', DeskPhoneEvent::query()->where('kind', DeskPhoneEvent::KEYS_CHANGED)->sole()->detail);

        $cfg = $this->fetch('805ec0123456.cfg')->getContent();
        foreach (['linekey.1.type = 15', 'linekey.2.type = 16', "linekey.2.value = {$sam->blfId()}", 'linekey.2.label = Sam Jones 230',
            'linekey.3.type = 16', "linekey.3.value = {$kim->blfId()}", 'linekey.4.type = 0', 'linekey.21.type = 0'] as $line) {
            $this->assertStringContainsString($line."\n", $cfg);
        }
        $this->assertStringNotContainsString('linekey.22.', $cfg);

        // Grandstream: virtual keys in sequence, and a ringing lamp calls rather than dialling the ** pickup prefix.
        $phone->update(['brand' => 'grandstream']);
        $xml = $this->fetch('cfg805ec0123456.xml')->getContent();
        $this->assertNotFalse(simplexml_load_string($xml));
        foreach (['<item name="pks.vpk.1.keyMode">Line</item>', '<item name="pks.vpk.2.keyMode">BLF</item>', "<item name=\"pks.vpk.2.value\">{$sam->blfId()}</item>",
            '<item name="pks.vpk.4.keyMode">None</item>', '<item name="account.1.sip.blf.callPickup.forcePrefix">Disabled</item>'] as $item) {
            $this->assertStringContainsString($item, $xml);
        }

        // Fanvil: six keys a page, line first.
        $phone->update(['brand' => 'fanvil']);
        $xml = $this->fetch('805ec0123456.cfg')->getContent();
        $conf = simplexml_load_string($xml);
        $this->assertSame(4, count($conf->dsskey->internal));
        $first = $conf->dsskey->internal[0]->Fkey;
        $this->assertSame(['2', 'SIP1'], [(string) $first[0]->Type, (string) $first[0]->Value]);
        $this->assertSame(['1', $sam->blfId().'@1/ba', 'Sam Jones 230'], [(string) $first[1]->Type, (string) $first[1]->Value, (string) $first[1]->Title]);
        $this->assertSame('0', (string) $first[3]->Type);

        // Someone made forward-only drops off; clearing them all clears the keys.
        app(\App\Support\UserTypes::class)->makeForwardOnly($kim);
        $this->assertSame(['Sam Jones'], $phone->fresh()->blfColleagues()->pluck('name')->all());
        $this->admin()->put("/admin/desk-phones/{$phone->id}/keys", [])->assertSessionHas('status', fn ($s) => str_contains($s, 'no busy lamp keys now'));
        $phone->update(['brand' => 'yealink']);
        $this->assertStringContainsString("linekey.2.type = 0\n", $this->fetch('805ec0123456.cfg')->getContent());
    }

    public function test_a_phone_set_up_by_hand_is_shown_its_key_values(): void
    {
        $sam = User::factory()->create(['name' => 'Sam']);
        $this->admin()->post('/admin/desk-phones', ['user_id' => $this->owner->id, 'label' => 'Desk']);
        $phone = DeskPhone::query()->sole();

        $this->admin()->put("/admin/desk-phones/{$phone->id}/keys", ['blf_keys' => [$sam->id]])
            ->assertSessionHas('status', "Desk's busy lamp keys saved.");
        $this->assertSame([], $this->voice->resynced);
        $this->get('/admin/desk-phones')->assertSee('Busy lamp keys (1): Sam')->assertSee($sam->blfId())->assertSee('set each key\'s type to', false);
    }

    public function test_mac_addresses_are_checked(): void
    {
        $this->admin()->post('/admin/desk-phones', ['user_id' => $this->owner->id, 'label' => 'A', 'brand' => 'yealink', 'mac' => 'not-a-mac'])
            ->assertSessionHasErrors('mac');
        $this->addPhone('yealink', '805ec0123456', 'A');
        $this->admin()->post('/admin/desk-phones', ['user_id' => $this->owner->id, 'label' => 'B', 'brand' => 'fanvil', 'mac' => '80:5e:c0:12:34:56'])
            ->assertSessionHasErrors(['mac' => 'Another phone here already has that MAC address.']);

        // Turning it off forgets the set-up.
        $phone = DeskPhone::query()->sole();
        $this->admin()->patch("/admin/desk-phones/{$phone->id}/provisioning", ['brand' => ''])->assertSessionHasNoErrors();
        $this->assertSame([null, null, null], [$phone->fresh()->brand, $phone->fresh()->mac, $phone->fresh()->admin_password]);
    }
}
