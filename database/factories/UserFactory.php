<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password-1234'),
            'remember_token' => Str::random(10),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (User $user) {
            $user->role ??= User::ROLE_USER;
        });
    }

    public function owner(): static
    {
        return $this->afterMaking(fn (User $user) => $user->role = User::ROLE_OWNER);
    }

    public function admin(): static
    {
        return $this->afterMaking(fn (User $user) => $user->role = User::ROLE_ADMIN);
    }

    /** Marks two-factor as set up so admin pages are reachable in tests. */
    public function withTwoFactor(): static
    {
        return $this->state(fn () => [])->afterMaking(function (User $user) {
            $user->forceFill([
                'two_factor_secret' => encrypt('TESTSECRET'),
                'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
                'two_factor_confirmed_at' => now(),
            ]);
        });
    }
}
