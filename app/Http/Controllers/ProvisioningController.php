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
 * address asked for, and (if the phone is locked) an allowed address.
 * Every download and refusal is recorded for admins and the operator.
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

        [$type, $body] = $provisioning->render($phone);
        $phone->forceFill(['provisioned_at' => now(), 'provisioned_ip' => $request->ip(), 'provisioned_agent' => $agent ?: null])->saveQuietly();
        DeskPhoneEvent::record(DeskPhoneEvent::PROVISIONED, $phone, detail: $agent ?: null);

        return response($body, 200, ['Content-Type' => $type, 'Cache-Control' => 'no-store, private']);
    }

    private function challenge(): Response
    {
        return response('', 401, ['WWW-Authenticate' => 'Basic realm="Phone settings"']);
    }
}
