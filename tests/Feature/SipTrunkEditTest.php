<?php

namespace Tests\Feature;

use App\Domain\Licensing\Plan;
use App\Jobs\SyncVoiceServer;
use App\Models\SipTrunk;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** A trunk's details can be corrected after it is saved. */
class SipTrunkEditTest extends TestCase
{
    use RefreshDatabase;

    private SipTrunk $trunk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->usePlan(Plan::Plus);
        $owner = User::factory()->owner()->withTwoFactor()->create();
        $this->acknowledgeTerms($owner);
        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()]);

        $this->trunk = SipTrunk::create([
            'name' => 'Twilio', 'provider' => 'twilio', 'inbound_ips' => [['ip' => '54.172.60.0', 'prefix' => 30]],
            'origination_uris' => ['sip:acme.pstn.twillo.com'], 'auth_username' => 'acme', 'auth_password' => 'old-secret', 'status' => 'active',
        ]);
    }

    private function save(array $changes): \Illuminate\Testing\TestResponse
    {
        return $this->put("/admin/trunks/{$this->trunk->id}", $changes + [
            'name' => 'Twilio', 'provider' => 'twilio', 'inbound_ips' => "54.172.60.0/30\n54.244.51.0/30",
            'origination_uris' => 'sip:acme.pstn.twilio.com', 'auth_username' => 'acme', 'auth_password' => '',
        ]);
    }

    public function test_the_form_shows_the_saved_details_but_not_the_password(): void
    {
        $this->get("/admin/trunks/{$this->trunk->id}")->assertSee("/admin/trunks/{$this->trunk->id}/edit");
        $this->get("/admin/trunks/{$this->trunk->id}/edit")->assertOk()
            ->assertSee('sip:acme.pstn.twillo.com')->assertSee('54.172.60.0/30')->assertSee('value="acme"', false)
            ->assertDontSee('old-secret')->assertSee('Leave the password blank to keep the saved one.');
    }

    public function test_saving_corrects_the_trunk_keeps_the_password_and_updates_the_voice_server(): void
    {
        Queue::fake();

        $this->save([])->assertRedirect("/admin/trunks/{$this->trunk->id}")->assertSessionHas('status', 'Trunk updated.');

        $trunk = $this->trunk->fresh();
        $this->assertSame(['sip:acme.pstn.twilio.com'], $trunk->origination_uris);
        $this->assertSame([['ip' => '54.172.60.0', 'prefix' => 30], ['ip' => '54.244.51.0', 'prefix' => 30]], $trunk->inbound_ips);
        $this->assertSame('old-secret', $trunk->auth_password);
        $this->assertSame($this->trunk->gatewayNames(), $trunk->gatewayNames());
        Queue::assertPushed(SyncVoiceServer::class);
    }

    public function test_a_new_password_replaces_the_old_and_no_username_means_no_password(): void
    {
        $this->save(['auth_password' => 'new-secret']);
        $this->assertSame('new-secret', $this->trunk->fresh()->auth_password);

        $this->save(['auth_username' => 'someone-else'])->assertSessionHasErrors('auth_password');

        $this->save(['auth_username' => '', 'register' => 1]);
        $this->assertSame([null, null, false], [$this->trunk->fresh()->auth_username, $this->trunk->fresh()->auth_password, $this->trunk->fresh()->register]);
    }
}
