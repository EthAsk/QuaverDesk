<?php

use App\Http\Controllers\Admin\AiSettingsController;
use App\Http\Controllers\Admin\CallingRulesController;
use App\Http\Controllers\Admin\DeskPhoneController;
use App\Http\Controllers\Admin\IvrMenuController;
use App\Http\Controllers\Admin\LicenceController;
use App\Http\Controllers\Admin\MailboxController;
use App\Http\Controllers\Admin\NumberController;
use App\Http\Controllers\Admin\RecordingSettingsController;
use App\Http\Controllers\Admin\RingGroupController;
use App\Http\Controllers\Admin\SipTrunkController;
use App\Http\Controllers\Admin\SoundController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\VoiceSettingsController;
use App\Http\Controllers\CallLogController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InviteController;
use App\Http\Controllers\ManifestController;
use App\Http\Controllers\Operator\AuditController;
use App\Http\Controllers\Operator\AuthController as OperatorAuthController;
use App\Http\Controllers\Operator\CompanyController;
use App\Http\Controllers\Operator\SupportController;
use App\Http\Controllers\PromptController;
use App\Http\Controllers\RecordingController;
use App\Http\Controllers\SecurityController;
use App\Http\Controllers\SetupController;
use App\Http\Controllers\FollowMeController;
use App\Http\Controllers\SoftphoneController;
use App\Http\Controllers\SoundFileController;
use App\Http\Controllers\SupportVisitController;
use App\Http\Controllers\Webhooks\VoiceController;
use Illuminate\Support\Facades\Route;

// Signed-in people land on the dashboard.
Route::redirect('/', '/dashboard');

// New people set their password from the link in their invite email.
Route::middleware(['signed', 'throttle:10,1'])->group(function () {
    Route::get('/invite/{user}', [InviteController::class, 'show'])->name('invite.show');
    Route::post('/invite/{user}', [InviteController::class, 'store'])->name('invite.store');
});

// Installable app description (fetched by the browser without cookies).
Route::get('/manifest.webmanifest', ManifestController::class)->name('manifest');

// auth.session: changing your password signs out your other sessions.
Route::middleware(['auth', 'auth.session'])->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    // Password is confirmed on entry so the two-factor buttons (POSTs) work straight away.
    Route::get('/security', SecurityController::class)->middleware('password.confirm')->name('security');
    Route::get('/calls', CallLogController::class)->name('calls');

    Route::get('/recordings', [RecordingController::class, 'index'])->name('recordings');
    Route::get('/recordings/{recording}', [RecordingController::class, 'show'])->name('recordings.show');
    Route::get('/recordings/{recording}/audio', [RecordingController::class, 'audio'])->name('recordings.audio');
    Route::post('/recordings/{recording}/analyse', [RecordingController::class, 'analyse'])
        ->middleware('throttle:ai')->name('recordings.analyse');
    Route::delete('/recordings/{recording}', [RecordingController::class, 'destroy'])->name('recordings.destroy');

    Route::middleware('acknowledged')->group(function () {
        Route::get('/softphone', [SoftphoneController::class, 'show'])->name('softphone');
        Route::get('/phone', [SoftphoneController::class, 'phone'])->name('phone');
        Route::post('/softphone/token', [SoftphoneController::class, 'token'])
            ->middleware('throttle:softphone-token')->name('softphone.token');
        Route::post('/softphone/park', [SoftphoneController::class, 'park'])->middleware('throttle:30,1')->name('softphone.park');
        Route::put('/softphone/mobile', [FollowMeController::class, 'update'])->name('follow-me.update');
        Route::post('/softphone/mobile/confirm', [FollowMeController::class, 'verify'])->middleware('throttle:3,60')->name('follow-me.verify');
    });

    Route::middleware(['role:owner,admin', 'admin2fa'])->group(function () {
        Route::get('/setup', [SetupController::class, 'show'])->middleware('password.confirm')->name('setup.acknowledge');
        Route::post('/setup', [SetupController::class, 'store'])->name('setup.store');

        Route::prefix('admin')->name('admin.')->group(function () {
            Route::get('/users', [UserController::class, 'index'])->name('users');
            Route::post('/users', [UserController::class, 'store'])->name('users.store');
            Route::patch('/users/{user}', [UserController::class, 'update'])->name('users.update');
            Route::patch('/users/{user}/extension', [UserController::class, 'extension'])->name('users.extension');
            Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
            Route::post('/users/{user}/invite', [UserController::class, 'invite'])->middleware('throttle:10,1')->name('users.invite');

            Route::middleware('feature:phone_numbers')->group(function () {
                Route::get('/numbers', [NumberController::class, 'index'])->name('numbers');
                Route::post('/numbers', [NumberController::class, 'store'])->middleware('within-plan:phone_numbers,admin.numbers')->name('numbers.store');
                Route::get('/numbers/{number}', [NumberController::class, 'edit'])->name('numbers.edit');
                Route::put('/numbers/{number}', [NumberController::class, 'update'])->name('numbers.update');
                Route::delete('/numbers/{number}', [NumberController::class, 'destroy'])->name('numbers.destroy');
            });

            Route::middleware('feature:routing')->group(function () {
                Route::get('/ring-groups', [RingGroupController::class, 'index'])->name('ring-groups');
                Route::get('/ring-groups/new', [RingGroupController::class, 'create'])->middleware('within-plan:ring_groups,admin.ring-groups')->name('ring-groups.create');
                Route::post('/ring-groups', [RingGroupController::class, 'store'])->middleware('within-plan:ring_groups,admin.ring-groups')->name('ring-groups.store');
                Route::get('/ring-groups/{group}', [RingGroupController::class, 'edit'])->name('ring-groups.edit');
                Route::put('/ring-groups/{group}', [RingGroupController::class, 'update'])->name('ring-groups.update');
                Route::delete('/ring-groups/{group}', [RingGroupController::class, 'destroy'])->name('ring-groups.destroy');

                Route::get('/ivr', [IvrMenuController::class, 'index'])->name('ivr');
                Route::get('/ivr/new', [IvrMenuController::class, 'create'])->middleware('within-plan:ivr_menus,admin.ivr')->name('ivr.create');
                Route::post('/ivr', [IvrMenuController::class, 'store'])->middleware('within-plan:ivr_menus,admin.ivr')->name('ivr.store');
                Route::get('/ivr/{menu}', [IvrMenuController::class, 'edit'])->name('ivr.edit');
                Route::put('/ivr/{menu}', [IvrMenuController::class, 'update'])->name('ivr.update');
                Route::delete('/ivr/{menu}', [IvrMenuController::class, 'destroy'])->name('ivr.destroy');
            });

            Route::middleware(['feature:byo_trunk', 'password.confirm'])->group(function () {
                Route::get('/trunks', [SipTrunkController::class, 'index'])->name('trunks');
                Route::get('/trunks/new', [SipTrunkController::class, 'create'])->middleware('within-plan:sip_trunks,admin.trunks')->name('trunks.create');
                Route::post('/trunks', [SipTrunkController::class, 'store'])->middleware('within-plan:sip_trunks,admin.trunks')->name('trunks.store');
                Route::delete('/trunks/default', [SipTrunkController::class, 'clearDefault'])->name('trunks.clear-default');
                Route::get('/trunks/{trunk}', [SipTrunkController::class, 'show'])->name('trunks.show');
                Route::get('/trunks/{trunk}/edit', [SipTrunkController::class, 'edit'])->name('trunks.edit');
                Route::put('/trunks/{trunk}', [SipTrunkController::class, 'update'])->name('trunks.update');
                Route::post('/trunks/{trunk}/default', [SipTrunkController::class, 'makeDefault'])->name('trunks.default');
                Route::delete('/trunks/{trunk}', [SipTrunkController::class, 'destroy'])->name('trunks.destroy');
            });

            Route::get('/sounds', [SoundController::class, 'index'])->name('sounds');
            Route::post('/sounds', [SoundController::class, 'store'])->middleware('throttle:20,1')->name('sounds.store');
            Route::patch('/sounds/{sound}', [SoundController::class, 'update'])->name('sounds.update');
            Route::delete('/sounds/{sound}', [SoundController::class, 'destroy'])->name('sounds.destroy');
            Route::put('/sounds/hold-music', [SoundController::class, 'holdMusic'])->name('sounds.hold-music');

            Route::get('/voice', [VoiceSettingsController::class, 'show'])->name('voice');
            Route::put('/voice', [VoiceSettingsController::class, 'update'])->name('voice.update');
            Route::post('/voice/preview', [VoiceSettingsController::class, 'preview'])->middleware('throttle:30,1')->name('voice.preview');

            Route::get('/mailboxes', [MailboxController::class, 'index'])->name('mailboxes');
            Route::get('/mailboxes/new', [MailboxController::class, 'create'])->name('mailboxes.create');
            Route::post('/mailboxes', [MailboxController::class, 'store'])->name('mailboxes.store');
            Route::get('/mailboxes/{mailbox}', [MailboxController::class, 'edit'])->name('mailboxes.edit');
            Route::put('/mailboxes/{mailbox}', [MailboxController::class, 'update'])->name('mailboxes.update');
            Route::delete('/mailboxes/{mailbox}', [MailboxController::class, 'destroy'])->name('mailboxes.destroy');

            Route::middleware(['feature:desk_phones', 'password.confirm'])->group(function () {
                Route::get('/desk-phones', [DeskPhoneController::class, 'index'])->name('desk-phones');
                Route::post('/desk-phones', [DeskPhoneController::class, 'store'])->name('desk-phones.store');
                Route::post('/desk-phones/{phone}/reveal', [DeskPhoneController::class, 'reveal'])->name('desk-phones.reveal');
                Route::delete('/desk-phones/{phone}', [DeskPhoneController::class, 'destroy'])->name('desk-phones.destroy');
            });

            Route::middleware(['feature:recording', 'password.confirm'])->group(function () {
                Route::get('/recording-settings', [RecordingSettingsController::class, 'show'])->name('recording-settings');
                Route::put('/recording-settings', [RecordingSettingsController::class, 'update'])->name('recording-settings.update');
            });

            Route::middleware(['feature:ai_transcripts', 'password.confirm'])->group(function () {
                Route::get('/ai', [AiSettingsController::class, 'show'])->name('ai');
                Route::put('/ai', [AiSettingsController::class, 'update'])->name('ai.update');
                Route::post('/ai/test', [AiSettingsController::class, 'test'])->middleware('throttle:5,1')->name('ai.test');
                Route::delete('/ai/key', [AiSettingsController::class, 'forget'])->name('ai.forget');
            });

            Route::middleware('password.confirm')->group(function () {
                Route::get('/calling-rules', [CallingRulesController::class, 'show'])->name('calling-rules');
                Route::put('/calling-rules', [CallingRulesController::class, 'update'])->name('calling-rules.update');
            });

            Route::middleware(['role:owner', 'password.confirm'])->group(function () {
                Route::get('/licence', [LicenceController::class, 'show'])->name('licence');
                Route::put('/licence', [LicenceController::class, 'update'])->name('licence.update');
                Route::delete('/licence', [LicenceController::class, 'destroy'])->name('licence.destroy');
                Route::post('/licence/refresh', [LicenceController::class, 'refresh'])->middleware('throttle:5,1')->name('licence.refresh');
            });
        });
    });
});

// Spoken prompts the voice server plays. Only prompts this app already made exist.
Route::get('/voice/prompts/{hash}.wav', PromptController::class)->where('hash', '[0-9a-f]{40}')->name('voice.prompt');
// Uploaded greetings and hold music, under unguessable names.
Route::get('/voice/sounds/{file}.wav', SoundFileController::class)->where('file', '[0-9a-f]{40}')->name('voice.sound');

// Voice server → app. No session, no CSRF; every request must carry the shared secret.
// On the hosted service, the company comes from the call itself (IdentifyCallTenant).
Route::prefix('webhooks/voice')->name('webhooks.voice.')->middleware(['carrier.signed', 'throttle:voice-webhooks'])->group(function () {
    Route::post('/outgoing', [VoiceController::class, 'outgoing'])->name('outgoing');
    Route::post('/inbound', [VoiceController::class, 'inbound'])->name('inbound');
    Route::post('/route', [VoiceController::class, 'route'])->name('route');
    Route::post('/after-dial', [VoiceController::class, 'afterDial'])->name('after-dial');
    Route::post('/transfer', [VoiceController::class, 'transfer'])->name('transfer');
    Route::post('/confirm-mobile', [VoiceController::class, 'confirmMobile'])->name('confirm-mobile');
    Route::post('/ivr-choice', [VoiceController::class, 'ivrChoice'])->name('ivr-choice');
    Route::post('/voicemail-saved', [VoiceController::class, 'voicemailSaved'])->name('voicemail-saved');
    Route::post('/recording-saved', [VoiceController::class, 'recordingSaved'])->name('recording-saved');
});

// --- Hosted service only (QUAVERDESK_MULTI_TENANT) --------------------------
// Operator console, on its own address (tenancy.operator_host; see IdentifyTenant).
Route::prefix('operator')->name('operator.')->middleware('hosted')->group(function () {
    Route::get('/login', [OperatorAuthController::class, 'showLogin'])->middleware('guest:operator')->name('login');
    Route::post('/login', [OperatorAuthController::class, 'login'])->middleware(['guest:operator', 'throttle:operator-auth'])->name('login.submit');
    Route::get('/two-factor', [OperatorAuthController::class, 'showTwoFactor'])->middleware('guest:operator')->name('two-factor');
    Route::post('/two-factor', [OperatorAuthController::class, 'twoFactor'])->middleware(['guest:operator', 'throttle:operator-auth'])->name('two-factor.submit');

    Route::middleware('auth:operator')->group(function () {
        Route::post('/logout', [OperatorAuthController::class, 'logout'])->name('logout');
        Route::redirect('/', '/operator/companies')->name('home');
        Route::get('/companies', [CompanyController::class, 'index'])->name('companies');
        Route::get('/companies/new', [CompanyController::class, 'create'])->name('companies.create');
        Route::post('/companies', [CompanyController::class, 'store'])->name('companies.store');
        Route::get('/companies/{tenant}', [CompanyController::class, 'show'])->name('companies.show');
        Route::put('/companies/{tenant}/plan', [CompanyController::class, 'plan'])->name('companies.plan');
        Route::post('/companies/{tenant}/status', [CompanyController::class, 'status'])->name('companies.status');
        Route::post('/companies/{tenant}/export', [CompanyController::class, 'export'])->middleware('throttle:5,1')->name('companies.export');
        Route::delete('/companies/{tenant}', [CompanyController::class, 'destroy'])->name('companies.destroy');
        Route::post('/companies/{tenant}/trunks/{trunk}', [CompanyController::class, 'trunk'])->whereNumber('trunk')->name('companies.trunk');
        Route::post('/companies/{tenant}/support', [SupportController::class, 'store'])->middleware('throttle:10,1')->name('companies.support');
        Route::get('/audit', AuditController::class)->name('audit');
    });
});

// A support visit starts from a single-use link on the company's own address.
Route::middleware('hosted')->group(function () {
    Route::get('/support/{token}', [SupportVisitController::class, 'start'])->where('token', '[0-9a-f]{64}')->middleware('throttle:10,1')->name('support.start');
    Route::post('/support/end', [SupportVisitController::class, 'end'])->middleware('auth')->name('support.end');
});

// Routes a deployment adds of its own, in routes/extra/*.php. None ship with QuaverDesk.
foreach (glob(__DIR__.'/extra/*.php') ?: [] as $extra) {
    require $extra;
}
