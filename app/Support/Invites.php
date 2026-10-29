<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\URL;

/**
 * Sign-in links for new people: a signed address, valid for a week, that lets
 * them choose a password. The link carries a fingerprint of the current
 * password, so it stops working as soon as a password is set.
 */
class Invites
{
    public const DAYS = 7;

    public static function link(User $user): string
    {
        return URL::temporarySignedRoute('invite.show', now()->addDays(self::DAYS), [
            'user' => $user->id,
            'h' => self::fingerprint($user),
        ]);
    }

    public static function valid(User $user, string $fingerprint): bool
    {
        return hash_equals(self::fingerprint($user), $fingerprint);
    }

    private static function fingerprint(User $user): string
    {
        return substr(hash_hmac('sha256', 'invite:'.$user->id.':'.$user->getAuthPassword(), (string) config('app.key')), 0, 32);
    }
}
