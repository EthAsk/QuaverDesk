<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/** Creates the first (owner) account from the server, so a fresh public install can't be claimed by someone else. */
class CreateOwner extends Command
{
    protected $signature = 'user:create-owner {--name=} {--email=} {--tenant= : Hosted service only: the company\'s slug}';

    protected $description = 'Create the owner account (only works while there are no users)';

    public function handle(Tenancy $tenancy): int
    {
        if (! $tenancy->enabled()) {
            return $this->create();
        }

        $tenant = Tenant::query()->firstWhere('slug', (string) $this->option('tenant'));
        if (! $tenant) {
            $this->error('Name the company with --tenant=SLUG (add one with tenant:create).');

            return self::FAILURE;
        }

        return $tenancy->run($tenant, fn () => $this->create());
    }

    private function create(): int
    {
        if (User::query()->exists()) {
            $this->error('This company already has users. Add more from Admin → Users.');

            return self::FAILURE;
        }

        $name = $this->option('name') ?: $this->ask('Owner name');
        $email = strtolower((string) ($this->option('email') ?: $this->ask('Owner email')));
        $password = $this->secret('Password (12+ characters, letters and numbers)');
        $confirm = $this->secret('Confirm password');

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password, 'password_confirmation' => $confirm],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255', 'unique:users,email'],
                'password' => ['required', 'string', Password::default(), 'confirmed'],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::INVALID;
        }

        DB::transaction(function () use ($name, $email, $password) {
            $user = new User(['name' => $name, 'email' => $email, 'password' => $password]);
            $user->role = User::ROLE_OWNER;
            $user->save();
        });

        $this->info("Owner {$email} created. Sign in, turn on two-factor, then complete Setup & terms.");

        return self::SUCCESS;
    }
}
