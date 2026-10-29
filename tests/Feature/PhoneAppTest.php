<?php

namespace Tests\Feature;

use App\Models\Call;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhoneAppTest extends TestCase
{
    use RefreshDatabase;

    public function test_phone_window_needs_terms_then_shows_the_phone(): void
    {
        $owner = User::factory()->owner()->withTwoFactor()->create();
        $colleague = User::factory()->create(['name' => 'Sam Patel']);

        $this->actingAs($owner)->get('/phone')->assertRedirect(route('setup.acknowledge'));

        $this->acknowledgeTerms($owner);
        $this->actingAs($owner)->get('/phone')
            ->assertOk()
            ->assertSee('data-token-url="'.route('softphone.token').'"', false)
            ->assertSee('data-dial="'.$colleague->extension.'" data-name="Sam Patel"', false)
            ->assertSee('rel="manifest"', false);
    }

    public function test_manifest_installs_the_phone_and_handles_tel_links(): void
    {
        $this->get('/manifest.webmanifest')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/manifest+json')
            ->assertJson([
                'start_url' => '/phone',
                'display' => 'standalone',
                'protocol_handlers' => [['protocol' => 'tel', 'url' => '/phone?dial=%s']],
            ]);
    }

    public function test_first_page_after_signing_in_asks_to_open_the_phone(): void
    {
        $user = User::factory()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'password-1234'])->assertRedirect();

        $this->get('/dashboard')->assertSee('data-phone-autostart', false);
        $this->get('/dashboard')->assertDontSee('data-phone-autostart', false);
    }

    public function test_call_history_offers_call_back(): void
    {
        $user = User::factory()->owner()->create();
        Call::create(['carrier_sid' => self::callId(1), 'user_id' => $user->id, 'direction' => 'inbound', 'from' => '+447700900123', 'to' => '+442079460000', 'status' => 'completed']);
        Call::create(['carrier_sid' => self::callId(2), 'user_id' => $user->id, 'direction' => 'outbound', 'from' => 'user-'.$user->id, 'to' => '999', 'status' => 'completed']);

        $this->actingAs($user)->get('/calls')
            ->assertSee('data-dial="+447700900123"', false)
            ->assertDontSee('data-dial="999"', false);
    }

    public function test_phone_is_docked_on_app_pages_once_calling_is_on(): void
    {
        $owner = User::factory()->owner()->withTwoFactor()->create();

        $this->actingAs($owner)->get('/calls')->assertOk()->assertDontSee('data-docked', false);

        $this->acknowledgeTerms($owner);
        $this->actingAs($owner)->get('/calls')
            ->assertSee('id="phone" class="phone-window phone-dock" data-docked data-turbo-permanent', false)
            ->assertSee('data-token-url="'.route('softphone.token').'"', false);

        // The phone's own window is not docked, and sign-in pages load in full.
        $this->actingAs($owner)->get('/phone')->assertDontSee('data-docked', false)->assertDontSee('id="dock-back"', false);
        // Popped out of the app, the way back closes the window instead of opening another copy of the app.
        $this->actingAs($owner)->get('/phone?popout=1')->assertSee('id="dock-back"', false)->assertDontSee('target="_blank" rel="noopener" title="Open', false);
        auth()->logout();
        $this->get('/login')->assertSee('<meta name="turbo-visit-control" content="reload">', false);
    }
}
