<?php

namespace Tests\Feature;

use App\Domain\Licensing\Plan;
use App\Models\DeskPhone;
use App\Models\DeskPhoneEvent;
use App\Models\User;
use App\Support\UserTypes;
use App\Voice\FreeSwitch\FreeSwitchServer;
use App\Voice\PhoneProvisioning;
use App\Voice\VoiceServer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Yealink DECT bases: one account per base, and handsets that can have their own extension. */
class DectBaseTest extends TestCase
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

    private function addBase(string $mac = '80:5E:C0:AA:BB:CC', string $label = 'Warehouse base'): DeskPhone
    {
        $this->admin()->post('/admin/desk-phones', ['user_id' => $this->owner->id, 'label' => $label, 'brand' => 'yealink_dect', 'mac' => $mac])
            ->assertSessionHasNoErrors();

        return DeskPhone::query()->where('label', $label)->sole();
    }

    private function addHandset(DeskPhone $base, User $user, string $label): DeskPhone
    {
        $this->admin()->post('/admin/desk-phones', ['user_id' => $user->id, 'label' => $label, 'base_id' => $base->id])->assertSessionHasNoErrors();

        return DeskPhone::query()->where('label', $label)->sole();
    }

    private function fetch(string $file): \Illuminate\Testing\TestResponse
    {
        $login = app(PhoneProvisioning::class)->login();

        return $this->call('GET', '/provision/'.$login['token'].'/'.$file, [], [], [], [
            'HTTP_USER_AGENT' => 'Yealink W70B 146.85.0.20', 'PHP_AUTH_USER' => $login['username'], 'PHP_AUTH_PW' => $login['password'],
        ]);
    }

    public function test_a_base_on_its_own_gives_every_handset_its_extension(): void
    {
        $base = $this->addBase();
        $cfg = $this->fetch('805ec0aabbcc.cfg')->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8')->getContent();

        foreach ([
            "account.1.user_name = {$base->sip_username}", "account.1.password = {$base->sip_password}",
            'account.1.sip_server.1.transport_type = 2', 'account.1.srtp_encryption = 2', 'voice_mail.number.1 = *00',
            'handset.1.name = Warehouse base', 'handset.1.incoming_lines = 1', 'handset.1.dial_out_default_line = 1',
            // The other handsets share account 1.
            'account.2.enable = 0', 'handset.2.incoming_lines = 1', 'handset.2.dial_out_default_line = 1',
            'account.10.enable = 0', 'handset.10.incoming_lines = 1',
            "static.security.user_password = admin:{$base->admin_password}", "static.security.user_password = user:{$base->admin_password}",
            'local_time.time_zone_name = United Kingdom(London)', 'static.auto_provision.repeat.enable = 1',
        ] as $line) {
            $this->assertStringContainsString($line."\n", $cfg);
        }
        $this->assertStringNotContainsString('account.11.', $cfg);
        $this->assertStringNotContainsString('linekey.', $cfg); // no busy lamp keys on DECT
        $this->assertStringNotContainsString('user_name.var', $cfg);
        $this->assertNotNull($base->refresh()->provisioned_at);
    }

    public function test_handsets_with_their_own_extension_get_their_own_account(): void
    {
        $base = $this->addBase();
        $sam = User::factory()->create(['name' => 'Sam Picker']);
        $jo = User::factory()->create(['name' => 'Jo Driver']);
        $one = $this->addHandset($base, $sam, 'Sam handset');
        $two = $this->addHandset($base, $jo, 'Jo handset');

        $this->assertSame([2, 3], [$one->handset, $two->handset]);
        $this->assertNull($one->brand);
        $this->assertSame([$base->sip_username, $base->sip_username], $this->voice->resynced); // the base is told each time

        $cfg = $this->fetch('805ec0aabbcc.cfg')->assertOk()->getContent();
        foreach ([
            "account.2.user_name = {$one->sip_username}", "account.2.password = {$one->sip_password}", 'account.2.display_name = Sam Picker',
            'account.2.sip_server.1.port = 5061', 'voice_mail.number.2 = *00',
            'handset.2.name = Sam handset', 'handset.2.incoming_lines = 2', 'handset.2.dial_out_lines = 2', 'handset.2.dial_out_default_line = 2',
            "account.3.user_name = {$two->sip_username}", 'handset.3.incoming_lines = 3',
            'account.4.enable = 0', 'handset.4.incoming_lines = 1',
        ] as $line) {
            $this->assertStringContainsString($line."\n", $cfg);
        }

        // A removed handset's number is free again, and the others keep theirs.
        $this->admin()->delete("/admin/desk-phones/{$one->id}")->assertRedirect();
        $cfg = $this->fetch('805ec0aabbcc.cfg')->assertOk()->getContent();
        $this->assertStringContainsString("account.2.enable = 0\n", $cfg);
        $this->assertStringContainsString("account.3.user_name = {$two->sip_username}\n", $cfg);
        $this->assertSame(2, $this->addHandset($base, $sam, 'Sam again')->handset);
    }

    public function test_handsets_share_the_bases_lock_and_have_no_set_up_of_their_own(): void
    {
        $base = $this->addBase();
        $this->admin()->patch("/admin/desk-phones/{$base->id}/lock", ['allowed_ips' => '203.0.113.7'])->assertSessionHasNoErrors();
        $handset = $this->addHandset($base, $this->owner, 'Spare handset');

        $this->assertSame($base->refresh()->lockCidrs(), $handset->refresh()->lockCidrs());
        $this->assertContains('203.0.113.7/32', $handset->lockCidrs());

        $this->admin()->patch("/admin/desk-phones/{$handset->id}/lock", ['allowed_ips' => ''])->assertNotFound();
        $this->admin()->patch("/admin/desk-phones/{$handset->id}/provisioning", ['brand' => 'yealink', 'mac' => '805ec0000001'])->assertNotFound();
        $this->admin()->put("/admin/desk-phones/{$handset->id}/keys", ['blf_keys' => []])->assertNotFound();
        $this->admin()->put("/admin/desk-phones/{$base->id}/keys", ['blf_keys' => []])->assertNotFound();

        // The base can't stop being a DECT base while it has handsets.
        $this->admin()->patch("/admin/desk-phones/{$base->id}/provisioning", ['brand' => 'yealink', 'mac' => '805ec0aabbcc'])
            ->assertSessionHasErrors('mac', null, 'provisioning');
        $this->assertSame('yealink_dect', $base->refresh()->brand);
    }

    public function test_only_dect_bases_take_handsets_and_a_base_fills_up(): void
    {
        $desk = DeskPhone::query()->create(['user_id' => $this->owner->id, 'label' => 'Desk', 'sip_username' => 'u1-aaaaaa', 'sip_password' => 'x', 'brand' => 'yealink', 'mac' => '805ec0000009']);
        $this->admin()->post('/admin/desk-phones', ['user_id' => $this->owner->id, 'label' => 'Nope', 'base_id' => $desk->id])->assertSessionHasErrors('base_id');

        $base = $this->addBase();
        for ($n = 2; $n <= DeskPhone::MAX_HANDSETS; $n++) {
            $this->addHandset($base, $this->owner, "Handset {$n}");
        }
        $this->admin()->post('/admin/desk-phones', ['user_id' => $this->owner->id, 'label' => 'One too many', 'base_id' => $base->id])->assertSessionHasErrors('base_id');
        $this->assertSame(DeskPhone::MAX_HANDSETS - 1, $base->handsets()->count());
    }

    public function test_removing_a_base_removes_its_handsets(): void
    {
        $base = $this->addBase();
        $handset = $this->addHandset($base, User::factory()->create(), 'Spare handset');

        $this->admin()->delete("/admin/desk-phones/{$base->id}")->assertSessionHas('status', fn ($s) => str_contains($s, 'and its 1 other handset removed'));
        $this->assertFalse(DeskPhone::query()->whereKey([$base->id, $handset->id])->exists());
        $this->assertSame(2, DeskPhoneEvent::query()->where('kind', DeskPhoneEvent::REMOVED)->count());
    }

    public function test_a_handset_whose_person_is_made_forward_only_is_turned_off(): void
    {
        $base = $this->addBase();
        $sam = User::factory()->create(['name' => 'Sam Picker']);
        $this->addHandset($base, $sam, 'Sam handset');

        $this->actingAs($this->owner);
        app(UserTypes::class)->makeForwardOnly($sam->refresh());

        $cfg = $this->fetch('805ec0aabbcc.cfg')->assertOk()->getContent();
        $this->assertStringContainsString("account.2.enable = 0\n", $cfg);
        $this->assertSame(0, $base->handsets()->count());
    }

    public function test_the_admin_page_shows_bases_and_their_handsets(): void
    {
        $base = $this->addBase();
        $this->addHandset($base, User::factory()->create(['name' => 'Sam Picker']), 'Sam handset');

        $this->admin()->get('/admin/desk-phones')->assertOk()
            ->assertSee('Handset 2 on Warehouse base')
            ->assertSee('Handset 2 is Sam handset')
            ->assertSee('Or a handset on a DECT base');
    }
}
