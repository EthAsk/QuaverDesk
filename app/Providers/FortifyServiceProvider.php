<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Models\User;
use App\Tenancy\Tenancy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);

        Fortify::loginView(fn () => view('auth.login', ['canRegister' => config('voip.web_setup') && ! config('tenancy.enabled') && ! User::query()->exists()]));
        Fortify::registerView(function () {
            abort_if(config('tenancy.enabled'), 404); // see CreateNewUser
            abort_if(! config('voip.web_setup') || User::query()->exists(), 404);

            return view('auth.register');
        });
        Fortify::twoFactorChallengeView(fn () => view('auth.two-factor-challenge'));
        Fortify::confirmPasswordView(fn () => view('auth.confirm-password'));
        Fortify::requestPasswordResetLinkView(fn () => view('auth.forgot-password'));
        Fortify::resetPasswordView(fn (Request $request) => view('auth.reset-password', ['request' => $request]));

        // Per company, person and address; and per address across every company,
        // so one address can't try passwords at many companies in turn.
        RateLimiter::for('login', function (Request $request) {
            $key = Str::transliterate(app(Tenancy::class)->id().'|'.Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return [Limit::perMinute(5)->by($key), Limit::perMinute(30)->by('login-address:'.$request->ip())];
        });

        RateLimiter::for('two-factor', fn (Request $request) => Limit::perMinute(5)->by($request->session()->get('login.id')));
    }
}
