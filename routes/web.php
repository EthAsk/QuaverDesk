<?php

use App\Http\Controllers\Admin\AiSettingsController;
use App\Http\Controllers\Admin\ApiTokenController;
use App\Http\Controllers\Api\ApiCallsController;
use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Admin\CallLogController as AdminCallLogController;
use App\Http\Controllers\Admin\CallingRulesController;
use App\Http\Controllers\Admin\DeskPhoneController;
use App\Http\Controllers\Admin\HubSpotController;
use App\Http\Controllers\Admin\IvrMenuController;
use App\Http\Controllers\Admin\LicenceController;
use App\Http\Controllers\Admin\MailboxController;
use App\Http\Controllers\Admin\MonitoringController;
use App\Http\Controllers\Admin\NumberController;
use App\Http\Controllers\Admin\OverviewController;
use App\Http\Controllers\Admin\ReceptionistController;
use App\Http\Controllers\Admin\RecordingSettingsController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\CallQueueController;
use App\Http\Controllers\Admin\RingGroupController;
use App\Http\Controllers\Admin\SipTrunkController;
use App\Http\Controllers\Admin\SsoSettingsController;
use App\Http\Controllers\Admin\SoundController;
use App\Http\Controllers\Admin\SystemController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\WebhookController;
use App\Http\Controllers\Admin\VoiceSettingsController;
use App\Http\Controllers\CallLogController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\WallboardController;
use App\Http\Controllers\InviteController;
use App\Http\Controllers\ManifestController;
use App\Http\Controllers\Operator\AuditController;
use App\Http\Controllers\Operator\AuthController as OperatorAuthController;
use App\Http\Controllers\Operator\CompanyController;
use App\Http\Controllers\Operator\SupportController;
use App\Http\Controllers\PromptController;
use App\Http\Controllers\CallSearchController;
use App\Http\Controllers\RecordingController;
use App\Http\Controllers\SecurityController;
use App\Http\Controllers\SetupController;
use App\Http\Controllers\FollowMeController;
use App\Http\Controllers\SoftphoneController;
use App\Http\Controllers\Scim\ScimUsersController;
use App\Http\Controllers\SsoController;
use App\Http\Controllers\SoundFileController;
use App\Http\Controllers\SupportVisitController;
use App\Http\Controllers\Webhooks\VoiceController;
use App\Http\Controllers\Webhooks\VoicemailMenuController;
use App\Http\Controllers\ProvisioningController;
use App\Http\Controllers\VoicemailAudioController;
use Illuminate\Support\Facades\Route;

// Signed-in people land on the dashboard.
Route::redirect('/', '/dashboard');

// New people set their password from the link in their invite email.
Route::middleware(['signed', 'throttle:10,1'])->group(function () {
    Route::get('/invite/{user}', [InviteController::class, 'show'])->name('invite.show');
    Route::post('/invite/{user}', [InviteController::class, 'store'])->name('invite.store');
});

// Sign in with Microsoft or Google (Pro). Not behind `guest`: a company without it answers 404 first.
Route::middleware('throttle:20,1')->whereIn('provider', ['microsoft', 'google'])->group(function () {
    Route::get('/sso/{provider}', [SsoController::class, 'redirect'])->name('sso.redirect');
    Route::get('/sso/{provider}/callback', [SsoController::class, 'callback'])->name('sso.callback');
});

// The company's directory (Entra, Okta…) keeps people in step here: SCIM 2.0, with the company's sync token.
Route::prefix('scim/v2')->middleware(['scim', 'throttle:120,1'])->group(function () {
    Route::get('/ServiceProviderConfig', [ScimUsersController::class, 'config']);
    Route::get('/Users', [ScimUsersController::class, 'index']);
    Route::post('/Users', [ScimUsersController::class, 'store']);
    Route::get('/Users/{id}', [ScimUsersController::class, 'show']);
    Route::put('/Users/{id}', [ScimUsersController::class, 'replace']);
    Route::patch('/Users/{id}', [ScimUsersController::class, 'update']);
    Route::delete('/Users/{id}', [ScimUsersController::class, 'destroy']);
});

// Read-only API for the company's own software, with one of its API tokens.
Route::prefix('api/v1')->middleware(['api.token', 'throttle:120,1'])->group(function () {
    Route::get('/me', [ApiController::class, 'me']);
    Route::get('/users', [ApiController::class, 'users']);
    Route::get('/users/{id}', [ApiController::class, 'showUser']);
    Route::get('/numbers', [ApiController::class, 'numbers']);
    Route::get('/calls', [ApiController::class, 'calls']);
    Route::get('/calls/{id}', [ApiController::class, 'showCall']);
    // Placing calls needs a calling token (Pro); read-only tokens get 403.
    Route::post('/calls', [ApiCallsController::class, 'store'])->middleware('throttle:60,1');
    Route::get('/call-requests/{id}', [ApiCallsController::class, 'show']);
});

// Installable app description (fetched by the browser without cookies).
Route::get('/manifest.webmanifest', ManifestController::class)->name('manifest');

// auth.session: changing your password signs out your other sessions.
Route::middleware(['auth', 'auth.session'])->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::patch('/my-queues/{queue}', [DashboardController::class, 'queue'])->middleware('feature:call_queues')->name('my-queues.update');
    // Password is confirmed on entry so the two-factor buttons (POSTs) work straight away.
    Route::get('/security', SecurityController::class)->middleware('password.confirm')->name('security');

    Route::middleware('feature:live_wallboard')->group(function () {
        Route::get('/wallboard', [WallboardController::class, 'show'])->name('wallboard');
        Route::get('/wallboard/data', [WallboardController::class, 'data'])->middleware('throttle:60,1')->name('wallboard.data');
    });
    // The wallboard used to be an admin page; old bookmarks still land on it.
    Route::redirect('/admin/wallboard', '/wallboard');
    Route::get('/calls', CallLogController::class)->name('calls');

    Route::get('/recordings', [RecordingController::class, 'index'])->name('recordings');
    Route::middleware('feature:ai_search')->group(function () {
        Route::get('/recordings/search', [CallSearchController::class, 'search'])->name('recordings.search');
        Route::post('/recordings/search', [CallSearchController::class, 'ask'])->middleware('throttle:ai')->name('recordings.ask');
    });
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
            Route::get('/', OverviewController::class)->name('overview');
            Route::get('/users', [UserController::class, 'index'])->name('users');
            Route::post('/users', [UserController::class, 'store'])->name('users.store');
            Route::patch('/users/{user}', [UserController::class, 'update'])->name('users.update');
            Route::patch('/users/{user}/extension', [UserController::class, 'extension'])->name('users.extension');
            Route::patch('/users/{user}/mobile', [UserController::class, 'mobile'])->name('users.mobile');
            Route::patch('/users/{user}/type', [UserController::class, 'type'])->name('users.type');
            Route::patch('/users/{user}/calling', [UserController::class, 'calling'])->name('users.calling');
            Route::patch('/users/{user}/voicemail', [UserController::class, 'voicemail'])->name('users.voicemail');
            Route::post('/users/{user}/confirm-mobile', [UserController::class, 'confirmMobile'])->middleware('throttle:5,1')->name('users.confirm-mobile');
            Route::patch('/users/{user}/reactivate', [UserController::class, 'reactivate'])->name('users.reactivate');
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

                Route::middleware('feature:ai_receptionist')->group(function () {
                    Route::get('/receptionists', [ReceptionistController::class, 'index'])->name('receptionists');
                    Route::get('/receptionists/new', [ReceptionistController::class, 'create'])->name('receptionists.create');
                    Route::post('/receptionists', [ReceptionistController::class, 'store'])->name('receptionists.store');
                    Route::get('/receptionists/{receptionist}', [ReceptionistController::class, 'edit'])->name('receptionists.edit');
                    Route::put('/receptionists/{receptionist}', [ReceptionistController::class, 'update'])->name('receptionists.update');
                    Route::delete('/receptionists/{receptionist}', [ReceptionistController::class, 'destroy'])->name('receptionists.destroy');
                });

                Route::get('/ivr', [IvrMenuController::class, 'index'])->name('ivr');
                Route::get('/ivr/new', [IvrMenuController::class, 'create'])->middleware('within-plan:ivr_menus,admin.ivr')->name('ivr.create');
                Route::post('/ivr', [IvrMenuController::class, 'store'])->middleware('within-plan:ivr_menus,admin.ivr')->name('ivr.store');
                Route::get('/ivr/{menu}', [IvrMenuController::class, 'edit'])->name('ivr.edit');
                Route::put('/ivr/{menu}', [IvrMenuController::class, 'update'])->name('ivr.update');
                Route::delete('/ivr/{menu}', [IvrMenuController::class, 'destroy'])->name('ivr.destroy');
            });

            Route::middleware('feature:monitoring')->group(function () {
                Route::get('/monitoring', [MonitoringController::class, 'show'])->name('monitoring');
                Route::post('/monitoring/acknowledge', [MonitoringController::class, 'acknowledge'])->name('monitoring.acknowledge');
                Route::post('/monitoring/supervisors', [MonitoringController::class, 'supervisors'])->name('monitoring.supervisors');
                Route::post('/wallboard/monitor/{call}', [MonitoringController::class, 'start'])->middleware('throttle:20,1')->name('wallboard.monitor');
            });

            Route::put('/wallboard/settings', [WallboardController::class, 'settings'])->middleware('feature:live_wallboard')->name('wallboard.settings');

            // Self-hosted installs: version, updates, health and backups (a hosted install answers 404).
            Route::get('/system', [SystemController::class, 'show'])->name('system');
            Route::post('/system/check-updates', [SystemController::class, 'checkUpdates'])->middleware('throttle:6,1')->name('system.check-updates');
            Route::post('/system/dismiss-update', [SystemController::class, 'dismissUpdate'])->name('system.dismiss-update');
            Route::post('/system/backup', [SystemController::class, 'backup'])->middleware('throttle:3,10')->name('system.backup');
            Route::get('/system/backups/{file}', [SystemController::class, 'download'])->middleware(['password.confirm', 'throttle:20,1'])->name('system.backup.download');

            Route::middleware('feature:reports')->group(function () {
                Route::get('/reports', [ReportController::class, 'show'])->name('reports');
                Route::middleware('feature:queue_reports')->post('/reports/schedule', [ReportController::class, 'schedule'])->name('reports.schedule');
                Route::get('/reports/calls.csv', [ReportController::class, 'calls'])->middleware('throttle:10,1')->name('reports.calls');
            });

            Route::middleware('feature:api')->group(function () {
                Route::get('/api', [ApiTokenController::class, 'index'])->name('api');
                Route::post('/api', [ApiTokenController::class, 'store'])->name('api.store');
                Route::post('/api/calling', [ApiTokenController::class, 'storeCalling'])->middleware('feature:api_calls')->name('api.calling');
                Route::post('/api/calling/pause', [ApiTokenController::class, 'pause'])->middleware('feature:api_calls')->name('api.pause');
                Route::delete('/api/{apiToken}', [ApiTokenController::class, 'destroy'])->name('api.destroy');
            });

            Route::middleware('feature:sso')->group(function () {
                Route::get('/sso', [SsoSettingsController::class, 'show'])->name('sso');
                Route::post('/sso/directory-token', [SsoSettingsController::class, 'makeToken'])->name('sso.token');
                Route::delete('/sso/directory-token', [SsoSettingsController::class, 'revokeToken'])->name('sso.token.revoke');
                Route::put('/sso/{provider}', [SsoSettingsController::class, 'update'])->whereIn('provider', ['microsoft', 'google'])->name('sso.update');
            });

            Route::middleware('feature:hubspot')->group(function () {
                Route::get('/hubspot', [HubSpotController::class, 'show'])->name('hubspot');
                Route::put('/hubspot', [HubSpotController::class, 'update'])->name('hubspot.update');
                Route::post('/hubspot/test', [HubSpotController::class, 'test'])->middleware('throttle:10,1')->name('hubspot.test');
                Route::delete('/hubspot', [HubSpotController::class, 'forget'])->name('hubspot.forget');
            });

            Route::middleware('feature:webhooks')->group(function () {
                Route::get('/webhooks', [WebhookController::class, 'index'])->name('webhooks');
                Route::post('/webhooks', [WebhookController::class, 'store'])->name('webhooks.store');
                Route::get('/webhooks/{webhook}', [WebhookController::class, 'show'])->name('webhooks.show');
                Route::put('/webhooks/{webhook}', [WebhookController::class, 'update'])->name('webhooks.update');
                Route::post('/webhooks/{webhook}/test', [WebhookController::class, 'test'])->middleware('throttle:10,1')->name('webhooks.test');
                Route::delete('/webhooks/{webhook}', [WebhookController::class, 'destroy'])->name('webhooks.destroy');
            });

            Route::middleware('feature:call_queues')->group(function () {
                Route::get('/queues', [CallQueueController::class, 'index'])->name('queues');
                Route::get('/queues/new', [CallQueueController::class, 'create'])->name('queues.create');
                Route::post('/queues', [CallQueueController::class, 'store'])->name('queues.store');
                Route::get('/queues/{queue}', [CallQueueController::class, 'edit'])->name('queues.edit');
                Route::put('/queues/{queue}', [CallQueueController::class, 'update'])->name('queues.update');
                Route::patch('/queues/{queue}/agents/{user}', [CallQueueController::class, 'agent'])->name('queues.agent');
                Route::delete('/queues/{queue}', [CallQueueController::class, 'destroy'])->name('queues.destroy');
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
                Route::patch('/desk-phones/{phone}/lock', [DeskPhoneController::class, 'lock'])->name('desk-phones.lock');
                Route::patch('/desk-phones/{phone}/provisioning', [DeskPhoneController::class, 'provisioning'])->name('desk-phones.provisioning');
                Route::put('/desk-phones/{phone}/keys', [DeskPhoneController::class, 'keys'])->name('desk-phones.keys');
                Route::post('/desk-phones/{phone}/admin-password', [DeskPhoneController::class, 'adminPassword'])->name('desk-phones.admin-password');
                Route::post('/desk-phones/{phone}/admin-password/new', [DeskPhoneController::class, 'newAdminPassword'])->name('desk-phones.admin-password.new');
                Route::post('/desk-phones/{phone}/approve', [DeskPhoneController::class, 'approve'])->name('desk-phones.approve');
                Route::post('/desk-phones/{phone}/setup-window', [DeskPhoneController::class, 'setupWindow'])->name('desk-phones.setup-window');
                Route::post('/desk-phones/{phone}/send-settings', [DeskPhoneController::class, 'sendSettings'])->middleware('throttle:10,1')->name('desk-phones.send-settings');
                Route::post('/desk-phones/provisioning-login', [DeskPhoneController::class, 'newLogin'])->name('desk-phones.provisioning-login');
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

            Route::get('/call-logs', AdminCallLogController::class)->name('call-logs');

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
// Desk phones download their settings (ProvisioningController). The company comes from the address.
Route::get('/provision/{token}/{file}', ProvisioningController::class)->where(['token' => '[0-9a-f]{32}', 'file' => '[A-Za-z0-9._-]{1,64}'])
    ->middleware('throttle:provisioning')->name('provision');
// A voicemail played under *00: signed, short-lived names (VoicemailAudio).
Route::get('/voice/messages/{file}.wav', VoicemailAudioController::class)->where('file', '[0-9]+-[0-9]+-[0-9a-f]{40}')
    ->middleware('throttle:voice-webhooks')->name('voice.message');

// Voice server → app. No session, no CSRF; every request must carry the shared secret.
// On the hosted service, the company comes from the call itself (IdentifyCallTenant).
Route::prefix('webhooks/voice')->name('webhooks.voice.')->middleware(['carrier.signed', 'throttle:voice-webhooks'])->group(function () {
    Route::post('/outgoing', [VoiceController::class, 'outgoing'])->name('outgoing');
    Route::post('/api-call', [VoiceController::class, 'apiCall'])->name('api-call');
    Route::post('/inbound', [VoiceController::class, 'inbound'])->name('inbound');
    Route::post('/route', [VoiceController::class, 'route'])->name('route');
    Route::post('/after-dial', [VoiceController::class, 'afterDial'])->name('after-dial');
    Route::post('/queue', [VoiceController::class, 'queue'])->name('queue');
    Route::post('/transfer', [VoiceController::class, 'transfer'])->name('transfer');
    Route::post('/confirm-mobile', [VoiceController::class, 'confirmMobile'])->name('confirm-mobile');
    Route::post('/ivr-choice', [VoiceController::class, 'ivrChoice'])->name('ivr-choice');
    Route::post('/receptionist', [VoiceController::class, 'receptionist'])->name('receptionist');
    Route::post('/voicemail-saved', [VoiceController::class, 'voicemailSaved'])->name('voicemail-saved');
    Route::post('/recording-saved', [VoiceController::class, 'recordingSaved'])->name('recording-saved');
    Route::post('/sound-recorded', [VoiceController::class, 'soundRecorded'])->name('sound-recorded');
    Route::post('/voicemail-menu', [VoicemailMenuController::class, 'menu'])->name('voicemail-menu');
    Route::post('/greeting-recorded', [VoicemailMenuController::class, 'greetingRecorded'])->name('greeting-recorded');
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
