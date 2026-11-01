<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DeskPhone;
use App\Models\DeskPhoneEvent;
use App\Models\User;
use App\Tenancy\TenantRule;
use App\Voice\PhoneProvisioning;
use App\Voice\VoiceServer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;

class DeskPhoneController extends Controller
{
    public function __construct(private readonly PhoneProvisioning $provisioning)
    {
    }

    public function index(VoiceServer $voice): View
    {
        try {
            $online = DeskPhone::service() ? $voice->registrations() : null;
        } catch (RuntimeException) {
            $online = null; // shown as unknown
        }

        return view('admin.desk-phones', [
            'online' => $online,
            'service' => DeskPhone::service(),
            'phones' => DeskPhone::query()->with('user:id,name')->orderBy('label')->get(),
            'users' => User::query()->seated()->orderBy('name')->get(['id', 'name', 'extension']),
            'reveal' => session('reveal_phone'),
            'revealAdmin' => session('reveal_admin'),
            'provisioning' => DeskPhone::service() ? ['url' => $this->provisioning->url()] + $this->provisioning->login() : null,
            'events' => DeskPhoneEvent::query()->with('user:id,name')->latest('id')->limit(100)->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $service = DeskPhone::service();
        abort_if($service === null, 409);

        $data = $request->validate([
            'user_id' => ['required', TenantRule::exists('users')->where('forward_only', 0)],
            'label' => ['required', 'string', 'max:60'],
            'allowed_ips' => ['nullable', 'string', 'max:255'],
            ...self::provisioningRules(),
        ]);
        $allowed = self::allowedIps($data['allowed_ips'] ?? null);
        [$brand, $mac] = self::brandAndMac($data);

        $username = 'u'.$data['user_id'].'-'.bin2hex(random_bytes(3));
        $password = self::strongPassword();

        // Saving queues a voice server sync, after which the phone can sign in.
        $phone = DeskPhone::create([
            'user_id' => $data['user_id'],
            'label' => $data['label'],
            'sip_username' => $username,
            'sip_password' => $password,
            'allowed_ips' => $allowed,
            'filter_lan' => $request->boolean('filter_lan'),
            'brand' => $brand,
            'mac' => $mac,
        ]);
        if ($brand) {
            $phone->newAdminPassword();
            $phone->save();
        }
        DeskPhoneEvent::record(DeskPhoneEvent::ADDED, $phone, $request->user(), "For {$phone->user->name}".($brand ? ', set up automatically as '.DeskPhone::BRANDS[$brand].' '.$phone->macLabel() : '').'.');

        return back()->with('reveal_phone', $phone->id)->with('status', 'Phone added. Enter these settings on the handset.');
    }

    /**
     * Locks a phone to the addresses it may sign in and call from (an office's
     * public IP, say), or unlocks it. Stolen settings then don't work elsewhere.
     */
    /** Automatic set-up: the handset's make and MAC address. Blank turns it off. */
    public function provisioning(Request $request, DeskPhone $phone): RedirectResponse
    {
        $data = $request->validateWithBag('provisioning', self::provisioningRules());
        [$brand, $mac] = self::brandAndMac($data, 'provisioning', $phone);

        $phone->forceFill(['brand' => $brand, 'mac' => $mac]);
        if ($brand && ! $phone->admin_password) {
            $phone->newAdminPassword();
        }
        if (! $brand) {
            $phone->forceFill(['admin_password' => null, 'provisioned_at' => null, 'provisioned_ip' => null, 'provisioned_agent' => null]);
        }
        $phone->save();
        DeskPhoneEvent::record(DeskPhoneEvent::PROVISIONING_SET, $phone, $request->user(), $brand ? DeskPhone::BRANDS[$brand].' '.$phone->macLabel() : 'Turned off');

        return back()->with('status', $brand
            ? "{$phone->label} will be set up automatically when it asks for its settings."
            : "{$phone->label} is no longer set up automatically.");
    }

    /** Shows the handset's web page password (page is behind password confirmation; recorded). */
    public function adminPassword(Request $request, DeskPhone $phone): RedirectResponse
    {
        abort_unless($phone->provisioned() && $phone->admin_password, 404);
        DeskPhoneEvent::record(DeskPhoneEvent::ADMIN_PASSWORD_SHOWN, $phone, $request->user());

        return back()->with('reveal_admin', $phone->id);
    }

    /** A new web page password for the handset; it gets it at its next download, which we ask for now. */
    public function newAdminPassword(Request $request, DeskPhone $phone, VoiceServer $voice): RedirectResponse
    {
        abort_unless($phone->provisioned(), 404);
        $phone->newAdminPassword();
        $phone->save();
        DeskPhoneEvent::record(DeskPhoneEvent::ADMIN_PASSWORD_CHANGED, $phone, $request->user());

        return back()->with('reveal_admin', $phone->id)->with('status', 'New admin password made. '.$this->resync($request, $phone, $voice));
    }

    /** Asks the phone to download its settings now rather than at its daily check. */
    public function sendSettings(Request $request, DeskPhone $phone, VoiceServer $voice): RedirectResponse
    {
        abort_unless($phone->provisioned(), 404);

        return back()->with('status', $this->resync($request, $phone, $voice));
    }

    private function resync(Request $request, DeskPhone $phone, VoiceServer $voice): string
    {
        try {
            $voice->resyncPhone($phone->sip_username);
        } catch (RuntimeException) {
            return "{$phone->label} couldn't be reached just now: it will get the change at its next daily check, or when it restarts.";
        }
        DeskPhoneEvent::record(DeskPhoneEvent::RESYNC_SENT, $phone, $request->user());

        return "{$phone->label} has been told to fetch its settings. If it's signed in, it does so within a minute.";
    }

    /**
     * The colleagues whose busy lamps (BLF keys) this phone shows, in the
     * order given. A phone set up automatically is sent them now; for one set
     * up by hand, the page lists the key values to enter.
     */
    public function keys(Request $request, DeskPhone $phone, VoiceServer $voice): RedirectResponse
    {
        $data = $request->validateWithBag('keys', [
            'blf_keys' => ['array', 'max:'.DeskPhone::MAX_BLF_KEYS],
            'blf_keys.*' => ['integer', 'distinct', Rule::notIn([$phone->user_id]), TenantRule::exists('users', 'id')->where('forward_only', 0)],
        ], ['blf_keys.max' => 'A phone can have up to '.DeskPhone::MAX_BLF_KEYS.' busy lamp keys.']);

        $phone->forceFill(['blf_keys' => array_values(array_map('intval', $data['blf_keys'] ?? []))])->save();
        $names = $phone->blfColleagues()->map(fn (User $u) => $u->name)->implode(', ');
        DeskPhoneEvent::record(DeskPhoneEvent::KEYS_CHANGED, $phone, $request->user(), $names !== '' ? $names : 'None');

        $status = $names !== '' ? "{$phone->label}'s busy lamp keys saved." : "{$phone->label} has no busy lamp keys now.";

        return back()->with('status', $phone->provisioned() ? $status.' '.$this->resync($request, $phone, $voice) : $status);
    }

    /** A new provisioning address and password for the company: the old ones stop working at once. */
    public function newLogin(Request $request): RedirectResponse
    {
        $this->provisioning->newLogin();
        DeskPhoneEvent::record(DeskPhoneEvent::LOGIN_CHANGED, null, $request->user());

        return back()->with('status', 'New provisioning address and password made. Phones keep their settings, but need these to fetch changes.');
    }

    /** @return array<string, list<mixed>> */
    private static function provisioningRules(): array
    {
        return [
            'brand' => ['nullable', Rule::in(array_keys(DeskPhone::BRANDS))],
            'mac' => ['nullable', 'string', 'max:20'],
        ];
    }

    /** @return array{0: ?string, 1: ?string} */
    private static function brandAndMac(array $data, string $bag = 'default', ?DeskPhone $except = null): array
    {
        $brand = $data['brand'] ?? null;
        if (! $brand) {
            return [null, null];
        }

        $mac = DeskPhone::normaliseMac((string) ($data['mac'] ?? ''));
        if ($mac === null) {
            throw ValidationException::withMessages(['mac' => 'Enter the MAC address from the label on the back of the phone, such as 80:5E:C0:12:34:56.'])->errorBag($bag);
        }

        if (DeskPhone::query()->where('mac', $mac)->whereKeyNot($except?->id)->exists()) {
            throw ValidationException::withMessages(['mac' => 'Another phone here already has that MAC address.'])->errorBag($bag);
        }

        return [$brand, $mac];
    }

    public function lock(Request $request, DeskPhone $phone): RedirectResponse
    {
        $data = $request->validateWithBag('lock', ['allowed_ips' => ['nullable', 'string', 'max:255']]);
        $phone->update(['allowed_ips' => self::allowedIps($data['allowed_ips'] ?? null, 'lock'), 'filter_lan' => $request->boolean('filter_lan')]);
        DeskPhoneEvent::record(DeskPhoneEvent::LOCK_CHANGED, $phone, $request->user(), $phone->allowed_ips
            ? 'Only from '.$phone->allowedIpsLabel().($phone->filter_lan ? ' (LAN addresses filtered too)' : ' and the local network')
            : 'From anywhere');

        return back()->with('status', match (true) {
            ! $phone->allowed_ips => "{$phone->label} can sign in from anywhere.",
            $phone->filter_lan => "{$phone->label} can now only sign in from {$phone->allowedIpsLabel()}.",
            default => "{$phone->label} can now only sign in from {$phone->allowedIpsLabel()}, or from the local network.",
        });
    }

    private static function allowedIps(?string $input, string $bag = 'default'): ?string
    {
        $cidrs = DeskPhone::normaliseAllowedIps((string) $input);
        if ($cidrs === null) {
            throw ValidationException::withMessages(['allowed_ips' => 'Enter up to '.DeskPhone::MAX_ALLOWED_IPS
                .' IP addresses, ranges or host names, such as 203.0.113.7, 203.0.113.0/24 (no wider than /8) or office.example.com.'])->errorBag($bag);
        }

        return $cidrs === '' ? null : $cidrs;
    }

    /** Shows a phone's settings again (page is behind password confirmation). */
    public function reveal(Request $request, DeskPhone $phone): RedirectResponse
    {
        DeskPhoneEvent::record(DeskPhoneEvent::SETTINGS_SHOWN, $phone, $request->user());

        return back()->with('reveal_phone', $phone->id);
    }

    public function destroy(Request $request, DeskPhone $phone): RedirectResponse
    {
        DeskPhoneEvent::record(DeskPhoneEvent::REMOVED, $phone, $request->user());
        $phone->delete();

        return back()->with('status', "{$phone->label} removed. It can no longer sign in.");
    }

    /** 20 characters with upper case, lower case and a digit, which every handset accepts. */
    public static function strongPassword(): string
    {
        do {
            $p = Str::password(20, symbols: false);
        } while (! preg_match('/[a-z]/', $p) || ! preg_match('/[A-Z]/', $p) || ! preg_match('/\d/', $p));

        return $p;
    }
}
