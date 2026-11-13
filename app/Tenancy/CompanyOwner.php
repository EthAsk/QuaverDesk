<?php

namespace App\Tenancy;

use App\Mail\UserInvited;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Invites;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/** A new hosted company's first person: its owner, invited by email to set a password. */
final class CompanyOwner
{
    public function __construct(private readonly Tenancy $tenancy)
    {
    }

    /** Creates the owner and emails the invite. @return string the invite link */
    public function invite(Tenant $tenant, string $name, string $email, string $from): string
    {
        return $this->tenancy->run($tenant, function () use ($tenant, $name, $email, $from) {
            $owner = new User(['name' => $name, 'email' => Str::lower($email), 'password' => Str::password(32)]);
            $owner->role = User::ROLE_OWNER;
            $owner->save();

            $link = $this->tenancy->withUrlsOf($tenant, fn () => Invites::link($owner));
            Mail::to($owner)->queue(new UserInvited($owner, $link, $from));

            return $link;
        });
    }
}
