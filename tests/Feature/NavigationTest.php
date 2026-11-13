<?php

namespace Tests\Feature;

use App\Domain\Licensing\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admins_get_the_overview_and_grouped_menu(): void
    {
        $this->usePlan(Plan::Pro);
        $owner = User::factory()->owner()->withTwoFactor()->create();
        $this->acknowledgeTerms($owner);

        $this->actingAs($owner)->get('/admin')->assertOk()
            ->assertSee('Overview')->assertSee('Licence expires')->assertSee('Features')
            ->assertSee('Phone system')->assertSee('People &amp; devices', false)->assertSee('Integrations')
            ->assertSee('href="'.route('wallboard').'"', false);
    }

    public function test_people_get_home_and_the_wallboard_but_not_admin(): void
    {
        $this->usePlan(Plan::Pro);
        $ann = User::factory()->create();

        $this->actingAs($ann)->get('/dashboard')->assertOk()
            ->assertSee('Home')->assertSee('href="'.route('wallboard').'"', false)
            ->assertDontSee('Licence expires')->assertDontSee('Phone system');
        $this->actingAs($ann)->get('/admin')->assertForbidden();
    }

    public function test_the_wallboard_link_is_hidden_without_the_feature(): void
    {
        $this->usePlan(Plan::Plus);
        $ann = User::factory()->create();

        $this->actingAs($ann)->get('/dashboard')->assertOk()->assertDontSee('href="'.route('wallboard').'"', false);
    }
}
