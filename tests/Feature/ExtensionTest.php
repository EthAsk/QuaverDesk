<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExtensionTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $owner = User::factory()->owner()->withTwoFactor()->create();
        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()]);

        return $owner;
    }

    public function test_everyone_gets_the_next_free_extension(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $this->assertSame(['201', '202'], [$a->extension, $b->extension]);

        $a->delete();
        $this->assertSame('201', User::factory()->create()->extension, 'gaps are reused');
    }

    public function test_admins_set_extensions_when_adding_and_later(): void
    {
        $this->admin(); // 201
        $this->post('/admin/users', ['name' => 'Sam', 'email' => 'sam@example.test', 'role' => 'user', 'extension' => '310'])
            ->assertSessionHasNoErrors();
        $sam = User::query()->firstWhere('email', 'sam@example.test');
        $this->assertSame('310', $sam->extension);

        $this->patch("/admin/users/{$sam->id}/extension", ['extension' => '4567'])->assertSessionHasNoErrors();
        $this->assertSame('4567', $sam->fresh()->extension);

        $this->patch("/admin/users/{$sam->id}/extension", ['extension' => '201'])->assertSessionHasErrorsIn('extension', 'extension');
        foreach (['999', '112', '101', '0207', '12', '123456', 'abc'] as $bad) {
            $this->patch("/admin/users/{$sam->id}/extension", ['extension' => $bad])->assertSessionHasErrorsIn('extension', 'extension');
        }
        $this->assertSame('4567', $sam->fresh()->extension);
    }

    public function test_phones_dial_and_show_extensions(): void
    {
        $owner = $this->admin();
        $this->acknowledgeTerms($owner);
        $sam = User::factory()->create(['name' => 'Sam Patel']);
        $sam->forceFill(['extension' => '305'])->save();

        $this->get('/phone')->assertSee('data-dial="305" data-name="Sam Patel"', false)->assertSee('ext 305');
    }
}
