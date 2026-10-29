<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

/**
 * Public registration only creates the FIRST user, who becomes the owner.
 * Everyone after that is added by an admin from the Users page.
 */
class CreateNewUser implements CreatesNewUsers
{
    /** @param array<string, string> $input */
    public function create(array $input): User
    {
        // On the hosted service the operator creates every company's owner, so a
        // company added without one can't be claimed by whoever visits first.
        abort_if(config('tenancy.enabled'), 404);

        if (! config('voip.web_setup')) {
            throw ValidationException::withMessages([
                'email' => 'Web set-up is turned off. Create the owner with `php artisan user:create-owner`.',
            ]);
        }

        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'string', Password::default(), 'confirmed'],
        ])->validate();

        return DB::transaction(function () use ($input) {
            // Lock so two simultaneous sign-ups cannot both become owner.
            if (User::query()->lockForUpdate()->exists()) {
                throw ValidationException::withMessages([
                    'email' => 'Registration is closed. Ask your administrator to add you.',
                ]);
            }

            $user = new User([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => $input['password'],
            ]);
            $user->role = User::ROLE_OWNER;
            $user->save();

            return $user;
        });
    }
}
