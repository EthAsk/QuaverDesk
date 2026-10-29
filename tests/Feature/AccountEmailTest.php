<?php

namespace Tests\Feature;

use App\Mail\SecurityAlert;
use App\Mail\UserInvited;
use App\Models\User;
use App\Support\Invites;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AccountEmailTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $owner = User::factory()->owner()->withTwoFactor()->create(['name' => 'Olivia Owner']);
        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()]);

        return $owner;
    }

    public function test_new_people_are_emailed_an_invite_instead_of_a_password(): void
    {
        Mail::fake();
        $this->admin();

        $this->post('/admin/users', ['name' => 'Sam Patel', 'email' => 'Sam@Example.test', 'role' => 'user'])
            ->assertSessionHas('invited')
            ->assertSessionMissing('new_password');

        Mail::assertQueued(UserInvited::class, fn (UserInvited $m) => $m->hasTo('sam@example.test')
            && $m->invitedBy === 'Olivia Owner'
            && str_contains($m->link, '/invite/'));
    }

    public function test_invite_link_sets_a_password_once(): void
    {
        $user = User::factory()->create();
        $link = Invites::link($user);
        auth()->logout();

        $this->get($link)->assertOk()->assertSee('Choose a password');
        $this->post($link, ['password' => 'a-new-password-123', 'password_confirmation' => 'a-new-password-123'])
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->assertTrue(Hash::check('a-new-password-123', $user->fresh()->password));

        // Used: the same link no longer works.
        auth()->logout();
        $this->get($link)->assertStatus(410);
        $this->post($link, ['password' => 'another-password-1', 'password_confirmation' => 'another-password-1'])->assertStatus(410);
    }

    public function test_invite_links_cannot_be_altered_or_used_late(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $link = Invites::link($user);

        $this->get(str_replace("/invite/{$user->id}", "/invite/{$other->id}", $link))->assertForbidden();

        $this->travel(Invites::DAYS + 1)->days();
        $this->get($link)->assertForbidden();
    }

    public function test_admins_can_email_a_fresh_sign_in_link(): void
    {
        Mail::fake();
        $this->admin();
        $user = User::factory()->create();

        $this->post("/admin/users/{$user->id}/invite")->assertSessionHas('status');

        Mail::assertQueued(UserInvited::class, fn (UserInvited $m) => $m->hasTo($user->email));
    }

    public function test_password_change_sends_a_security_alert(): void
    {
        Mail::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->put('/user/password', [
            'current_password' => 'password-1234',
            'password' => 'a-new-password-123',
            'password_confirmation' => 'a-new-password-123',
        ])->assertSessionHasNoErrors();

        Mail::assertQueued(SecurityAlert::class, fn (SecurityAlert $m) => $m->hasTo($user->email) && $m->what === 'Your password was changed');
    }

    public function test_a_password_change_signs_out_other_sessions(): void
    {
        $user = User::factory()->create();
        $stolen = ['password_hash_web' => $user->getAuthPassword()]; // a session started with the old password

        $this->actingAs($user)->withSession($stolen)->get('/calls')->assertOk();

        $user->forceFill(['password' => 'a-brand-new-password-1'])->save();
        $this->actingAs($user)->withSession($stolen)->get('/calls')->assertRedirect('/login');
    }

    public function test_a_password_change_also_changes_the_phone_password(): void
    {
        $user = User::factory()->create();
        $old = $user->sipSecret();

        $this->actingAs($user)->put('/user/password', [
            'current_password' => 'password-1234',
            'password' => 'a-new-password-123',
            'password_confirmation' => 'a-new-password-123',
        ])->assertSessionHasNoErrors();

        $this->assertNotSame($old, $user->fresh()->sipSecret());
    }
}
