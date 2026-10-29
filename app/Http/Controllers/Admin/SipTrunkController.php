<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SipTrunk;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * SIP trunks connect the voice server to the phone network. Saving a trunk
 * queues a voice server sync, which adds it as a gateway and allows the
 * provider's addresses to send us calls.
 */
class SipTrunkController extends Controller
{
    public function index(): View
    {
        return view('admin.trunks.index', [
            'trunks' => SipTrunk::query()->withCount('numbers')->orderBy('name')->get(),
            'providers' => config('sip_providers'),
        ]);
    }

    public function create(): View
    {
        return view('admin.trunks.create', [
            'providers' => config('sip_providers'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $trunk = SipTrunk::create($this->validated($request) + ['status' => 'active']);

        return redirect()->route('admin.trunks.show', $trunk);
    }

    public function edit(SipTrunk $trunk): View
    {
        return view('admin.trunks.create', [
            'trunk' => $trunk,
            'providers' => config('sip_providers'),
        ]);
    }

    /** A blank password keeps the saved one; a blank username switches the trunk to sign-in by IP address. */
    public function update(Request $request, SipTrunk $trunk): RedirectResponse
    {
        $trunk->update($this->validated($request, $trunk));

        return redirect()->route('admin.trunks.show', $trunk)->with('status', 'Trunk updated.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?SipTrunk $trunk = null): array
    {
        $keepPassword = $trunk?->auth_password !== null && $request->input('auth_username') === $trunk->auth_username;

        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'provider' => ['required', Rule::in(array_keys(config('sip_providers')))],
            'inbound_ips' => ['required', 'string', 'max:2000'],
            'origination_uris' => ['required', 'string', 'max:2000'],
            'auth_username' => ['nullable', 'string', 'max:100', 'regex:/^[^\s$]+$/'],
            'auth_password' => ['nullable', ...($keepPassword ? [] : ['required_with:auth_username']), 'string', 'max:200', 'regex:/^[^\s$]+$/'],
            'register' => ['nullable', 'boolean'],
        ], [
            'auth_username.regex' => 'The username cannot contain spaces or "$".',
            'auth_password.regex' => 'The password cannot contain spaces or "$".',
        ]);

        $username = $data['auth_username'] ?? null;

        return [
            'name' => $data['name'],
            'provider' => $data['provider'],
            'inbound_ips' => self::parseIps($data['inbound_ips']),
            'origination_uris' => self::parseUris($data['origination_uris']),
            'auth_username' => $username,
            'auth_password' => $username === null ? null : (($data['auth_password'] ?? null) ?: $trunk?->auth_password),
            'register' => $request->boolean('register') && $username !== null,
        ];
    }

    public function show(SipTrunk $trunk): View
    {
        return view('admin.trunks.show', [
            'trunk' => $trunk->loadCount('numbers'),
            'provider' => config('sip_providers.'.$trunk->provider.'.name', $trunk->provider),
            'address' => config('voip.freeswitch.trunk_address'),
        ]);
    }

    public function makeDefault(SipTrunk $trunk): RedirectResponse
    {
        abort_unless($trunk->isActive(), 409);

        DB::transaction(function () use ($trunk) {
            SipTrunk::query()->update(['default_outbound' => false]);
            $trunk->update(['default_outbound' => true]);
        });

        return back()->with('status', "Outgoing calls now go through {$trunk->name}. Set a caller ID number from this trunk under Calling rules.");
    }

    public function clearDefault(): RedirectResponse
    {
        SipTrunk::query()->update(['default_outbound' => false]);

        return back()->with('status', 'No trunk is set for outgoing calls, so calls to phone numbers are off.');
    }

    public function destroy(SipTrunk $trunk): RedirectResponse
    {
        $trunk->delete();

        return redirect()->route('admin.trunks')->with('status', "Trunk \"{$trunk->name}\" removed.");
    }

    /** @return list<array{ip: string, prefix: int}> */
    public static function parseIps(string $text): array
    {
        $out = [];
        foreach (preg_split('/[\s,]+/', $text, -1, PREG_SPLIT_NO_EMPTY) as $line) {
            [$ip, $prefix] = array_pad(explode('/', $line, 2), 2, '32');
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || ! ctype_digit($prefix) || (int) $prefix < 8 || (int) $prefix > 32) {
                throw ValidationException::withMessages(['inbound_ips' => "\"{$line}\" is not a valid IPv4 address or range (e.g. 203.0.113.10 or 203.0.113.0/28)."]);
            }
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw ValidationException::withMessages(['inbound_ips' => "\"{$line}\" is a private address; your provider's public SBC address is needed."]);
            }
            $out[] = ['ip' => $ip, 'prefix' => (int) $prefix];
        }

        if ($out === [] || count($out) > 20) {
            throw ValidationException::withMessages(['inbound_ips' => 'Enter between 1 and 20 addresses.']);
        }

        return $out;
    }

    /** @return list<string> */
    public static function parseUris(string $text): array
    {
        $out = [];
        foreach (preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY) as $uri) {
            if (! preg_match('/^sips?:([A-Za-z0-9_.+-]+@)?[A-Za-z0-9.-]+\.[A-Za-z0-9-]+(:\d{1,5})?(;[A-Za-z0-9=._-]+)*$/', $uri)) {
                throw ValidationException::withMessages(['origination_uris' => "\"{$uri}\" is not a SIP address (e.g. sip:sbc.provider.example or sip:203.0.113.10:5060)."]);
            }
            $out[] = $uri;
        }

        if ($out === [] || count($out) > 10) {
            throw ValidationException::withMessages(['origination_uris' => 'Enter between 1 and 10 SIP addresses.']);
        }

        return $out;
    }
}
