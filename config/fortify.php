<?php

use Laravel\Fortify\Features;

return [
    'guard' => 'web',
    'passwords' => 'users',
    'username' => 'email',
    'email' => 'email',
    'lowercase_usernames' => true,
    'home' => '/dashboard',
    'prefix' => '',
    'domain' => null,
    'middleware' => ['web'],
    'limiters' => [
        'login' => 'login',
        'two-factor' => 'two-factor',
        'passkeys' => 'passkeys',
    ],

    // Passkeys belong to this site's address (APP_URL) only, so look-alike sites can't use them.
    'passkeys' => [
        'relying_party_id' => parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST),
        'allowed_origins' => [rtrim((string) env('APP_URL', 'http://localhost'), '/')],
        'timeout' => 120000,
    ],
    'views' => true,
    'features' => [
        // Registration only works while no users exist (creates the owner).
        Features::registration(),
        Features::resetPasswords(),
        Features::updatePasswords(),
        Features::twoFactorAuthentication([
            'confirm' => true,
            'confirmPassword' => true,
        ]),
        // Sign in with a passkey (Plus and Pro: see EnsurePasskeysLicensed).
        Features::passkeys([
            'confirmPassword' => true,
        ]),
    ],
];
