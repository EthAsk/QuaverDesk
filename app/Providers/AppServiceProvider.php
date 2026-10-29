<?php

namespace App\Providers;

use App\Domain\Licensing\Feature;
use App\Mail\Transport\GraphTransport;
use App\Jobs\SyncVoiceServer;
use App\Models\Mailbox;
use App\Models\IvrMenu;
use App\Models\Passkey;
use App\Jobs\WarmPrompts;
use App\Models\DeskPhone;
use App\Models\SipTrunk;
use App\Models\User;
use App\Domain\Routing\InboundRouter;
use App\Support\DatabaseRoutingDirectory;
use App\Support\LicenceManager;
use App\Support\RouteCallbackUrls;
use App\Models\Tenant;
use App\Tenancy\Tenancy;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use App\Voice\FreeSwitch\FreeSwitchServer;
use App\Voice\Audio;
use App\Voice\Prompts;
use App\Voice\Voices;
use App\Voice\VoiceServer;
use App\Mail\SecurityAlert;
use App\Http\Controllers\SoftphoneController;
use App\Models\Acknowledgement;
use App\Models\Sound;
use Illuminate\Support\Facades\View;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Mail;
use Laravel\Fortify\Events\PasswordUpdatedViaController;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Passkeys\Events\PasskeyDeleted;
use Laravel\Passkeys\Events\PasskeyRegistered;
use Laravel\Passkeys\Passkeys;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Tenancy::class);
        $this->app->scoped(LicenceManager::class);

        $this->app->bind(InboundRouter::class, fn ($app) => new InboundRouter(
            $app->make(DatabaseRoutingDirectory::class),
            new RouteCallbackUrls(),
        ));

        $this->app->bind(Voices::class, fn () => new Voices(config('voip.tts')));
        $this->app->bind(Prompts::class, fn ($app) => new Prompts($app->make(Voices::class)->config()));
        $this->app->bind(Audio::class, fn () => new Audio(config('voip.ffmpeg') ?: 'ffmpeg'));
        $this->app->bind(VoiceServer::class, fn () => new FreeSwitchServer(config('voip.freeswitch')));
    }

    public function boot(): void
    {
        $this->bootTenancy();

        Mail::extend('graph', fn (array $config) => new GraphTransport(
            (string) $config['tenant_id'], (string) $config['client_id'], (string) $config['client_secret'],
        ));

        // Load balancer / proxy addresses, so generated URLs use the public https address.
        if ($proxies = config('voip.trusted_proxies')) {
            TrustProxies::at(array_values(array_filter(array_map('trim', explode(',', $proxies)))));
        }

        Password::defaults(function () {
            $rule = Password::min(12)->letters()->numbers();

            return $this->app->isProduction() ? $rule->uncompromised() : $rule;
        });

        // Whoever can sign in to the voice server changes with people, phones and trunks.
        $sync = fn () => SyncVoiceServer::dispatch()->afterCommit();
        User::created($sync);
        User::deleted($sync);
        User::updated(fn (User $u) => $u->wasChanged(['name', 'extension']) && $sync());
        DeskPhone::saved($sync);
        DeskPhone::deleted($sync);
        SipTrunk::saved($sync);
        SipTrunk::deleted($sync);

        // New or changed greetings get their audio made straight away, not mid-call.
        IvrMenu::saved(fn (IvrMenu $m) => $m->wasChanged('greeting') || $m->wasRecentlyCreated ? WarmPrompts::dispatch([$m->greeting]) : null);
        Mailbox::saved(fn (Mailbox $m) => $m->wasChanged('greeting') || $m->wasRecentlyCreated ? WarmPrompts::dispatch([$m->greeting]) : null);

        // Tell people about changes to how they sign in.
        $alert = fn (string $what) => function ($event) use ($what) {
            if ($event->user instanceof User) {
                Mail::to($event->user)->queue(new SecurityAlert($event->user, $what, request()?->ip()));
            }
        };
        // A new password also means a new softphone password, and anyone signed in
        // to the voice server with the old one is signed out.
        $rekeyPhone = function ($event) {
            $event->user->rotateSipSecret();
            SyncVoiceServer::dispatch()->afterCommit();
        };
        Event::listen(PasswordUpdatedViaController::class, $rekeyPhone);
        Event::listen(PasswordReset::class, $rekeyPhone);
        Event::listen(PasswordUpdatedViaController::class, $alert('Your password was changed'));
        Event::listen(PasswordReset::class, $alert('Your password was reset'));
        Event::listen(TwoFactorAuthenticationConfirmed::class, $alert('Two-factor authentication was turned on'));
        Event::listen(TwoFactorAuthenticationDisabled::class, $alert('Two-factor authentication was turned off'));
        Event::listen(PasskeyRegistered::class, $alert('A passkey was added'));
        Event::listen(PasskeyDeleted::class, $alert('A passkey was removed'));

        // The phone docks in the corner of every app page once calling is switched on.
        View::composer('partials.greeting-sound', fn ($view) => $view->with('sounds', Sound::query()->orderBy('name')->get()));
        View::composer('layouts.app', fn ($view) => $view->with('phoneDock', auth()->check() && Acknowledgement::currentAccepted()));
        View::composer('partials.phone-dock', fn ($view) => $view->with(SoftphoneController::data(auth()->user())));

        // The first page after signing in may pop the phone out (if this computer wants that).
        Event::listen(Login::class, function () {
            if (request()->hasSession()) {
                request()->session()->flash('phone_autostart', true);
            }
        });

        Passkeys::usePasskeyModel(Passkey::class);

        RateLimiter::for('passkeys', fn (Request $request) => Limit::perMinute(10)->by($request->user()?->id ?: app(Tenancy::class)->id().'|'.$request->ip()));
        // Both operator sign-in steps together, per address.
        RateLimiter::for('operator-auth', fn (Request $request) => Limit::perMinute(10)->by('operator:'.$request->ip()));
        // What one company does can't use up what the others share.
        RateLimiter::for('voice-webhooks', fn () => ($id = app(Tenancy::class)->id()) && app(Tenancy::class)->enabled()
            ? Limit::perMinute((int) config('tenancy.webhooks_per_minute'))->by('voice:tenant:'.$id)
            : Limit::none());
        RateLimiter::for('ai', fn (Request $request) => [
            Limit::perMinute(20)->by('ai:user:'.$request->user()?->id),
            Limit::perHour((int) config('tenancy.ai_per_hour'))->by('ai:tenant:'.app(Tenancy::class)->id()),
        ]);
        RateLimiter::for('softphone-token', fn (Request $request) => Limit::perMinute(10)->by($request->user()?->id ?: $request->ip()));

        // @feature('ai_summaries') … @else … @endfeature
        Blade::if('feature', fn (string $feature) => app(LicenceManager::class)->allows(Feature::from($feature)));
    }

    /** Queued jobs remember which company they were for, and run as it. */
    private function bootTenancy(): void
    {
        $tenancy = $this->app->make(Tenancy::class);

        if ($tenancy->enabled()) {
            // Each company's sign-in cookie stays on its own web address.
            config(['session.domain' => null]);
        }

        Queue::createPayloadUsing(fn () => ['tenant_id' => $tenancy->id()]);

        Event::listen(function (JobProcessing $event) use ($tenancy) {
            $id = $event->job->payload()['tenant_id'] ?? null;
            $tenancy->set($tenant = $id ? Tenant::query()->find($id) : null);

            // Links in emails sent from the queue go to the company's own address.
            if ($tenancy->enabled()) {
                URL::forceRootUrl($tenant?->url());
            }
        });
    }
}
