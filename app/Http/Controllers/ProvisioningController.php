<?php

namespace App\Http\Controllers;

use App\Domain\Licensing\Feature;
use App\Models\DeskPhone;
use App\Models\DeskPhoneEvent;
use App\Support\LicenceManager;
use App\Voice\PhoneProvisioning;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Desk phones download their settings here (see PhoneProvisioning). Needs the
 * company's provisioning address and login, a phone registered with the MAC
 * address asked for, (if the phone is locked) an allowed address, and either
 * an open set-up window or the address the phone set itself up from. A
 * download from anywhere else waits for an admin to approve it, since a MAC
 * address isn't secret. Every download and refusal is recorded for admins and
 * the operator.
 */
class ProvisioningController extends Controller
{
    public function __invoke(Request $request, string $token, string $file, PhoneProvisioning $provisioning, LicenceManager $licences): Response
    {
        abort_unless($licences->allows(Feature::DeskPhones) && DeskPhone::service(), 404);
        abort_unless(hash_equals($provisioning->login()['token'], $token), 404);

        // Phones send their login once asked for it.
        if ($request->getUser() === null) {
            return $this->challenge();
        }

        $wanted = PhoneProvisioning::parse($file);
        $mac = $wanted[0] ?? null;
        $phone = $wanted === null ? null : DeskPhone::query()->with('user')->where('mac', $mac)->whereIn('brand', PhoneProvisioning::brandsFor($wanted[1]))->first();
        $agent = mb_substr((string) $request->userAgent(), 0, 255);

        if (! $provisioning->allows($token, $request->getUser(), $request->getPassword())) {
            DeskPhoneEvent::record(DeskPhoneEvent::REFUSED, $phone, detail: 'Wrong provisioning login.'.($agent !== '' ? ' '.$agent : ''));

            return $this->challenge();
        }

        // Model-wide files, firmware and the like: nothing to send.
        if ($wanted === null) {
            abort(404);
        }

        if ($phone === null || $phone->user === null || $phone->user->isForwardOnly()) {
            DeskPhoneEvent::record(DeskPhoneEvent::REFUSED, $phone, detail: 'No phone is set up with MAC address '.strtoupper(implode(':', str_split($mac, 2))).'.');
            abort(404);
        }

        $cidrs = $phone->lockCidrs();
        if ($cidrs !== null && ! IpUtils::checkIp((string) $request->ip(), $cidrs)) {
            DeskPhoneEvent::record(DeskPhoneEvent::REFUSED, $phone, detail: 'Asked from outside the addresses the phone is locked to.');
            abort(403);
        }

        $ip = (string) $request->ip();
        if (! $phone->acceptsDownloadFrom($ip)) {
            // Logged once per address an hour, so a phone retrying doesn't flood the log.
            if ($phone->waiting_ip !== $ip || $phone->waiting_at === null || $phone->waiting_at->lt(now()->subHour())) {
                DeskPhoneEvent::record(DeskPhoneEvent::WAITING, $phone, detail: 'Asked from an address it has not used before.'.($agent !== '' ? ' '.$agent : ''));
            }
            $phone->forceFill(['waiting_ip' => $ip, 'waiting_agent' => $agent ?: null, 'waiting_at' => now()])->saveQuietly();
            abort(403);
        }

        [$type, $body] = $provisioning->render($phone);
        // Set up: this address is trusted from now on, and the window closes.
        $phone->forceFill([
            'provisioned_at' => now(), 'provisioned_ip' => $ip, 'provisioned_agent' => $agent ?: null,
            'trusted_ip' => $ip, 'setup_until' => null, 'waiting_ip' => null, 'waiting_agent' => null, 'waiting_at' => null,
        ])->saveQuietly();
        DeskPhoneEvent::record(DeskPhoneEvent::PROVISIONED, $phone, detail: $agent ?: null);

        return response($body, 200, ['Content-Type' => $type, 'Cache-Control' => 'no-store, private']);
    }

    private function challenge(): Response
    {
        return response('', 401, ['WWW-Authenticate' => 'Basic realm="Phone settings"']);
    }
}
