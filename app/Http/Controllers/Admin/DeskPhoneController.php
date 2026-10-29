<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DeskPhone;
use App\Models\User;
use App\Tenancy\TenantRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class DeskPhoneController extends Controller
{
    public function index(): View
    {
        return view('admin.desk-phones', [
            'service' => DeskPhone::service(),
            'phones' => DeskPhone::query()->with('user:id,name')->orderBy('label')->get(),
            'users' => User::query()->orderBy('name')->get(['id', 'name']),
            'reveal' => session('reveal_phone'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $service = DeskPhone::service();
        abort_if($service === null, 409);

        $data = $request->validate([
            'user_id' => ['required', TenantRule::exists('users')],
            'label' => ['required', 'string', 'max:60'],
        ]);

        $username = 'u'.$data['user_id'].'-'.bin2hex(random_bytes(3));
        $password = self::strongPassword();

        // Saving queues a voice server sync, after which the phone can sign in.
        $phone = DeskPhone::create([
            'user_id' => $data['user_id'],
            'label' => $data['label'],
            'sip_username' => $username,
            'sip_password' => $password,
        ]);

        return back()->with('reveal_phone', $phone->id)->with('status', 'Phone added. Enter these settings on the handset.');
    }

    /** Shows a phone's settings again (page is behind password confirmation). */
    public function reveal(DeskPhone $phone): RedirectResponse
    {
        return back()->with('reveal_phone', $phone->id);
    }

    public function destroy(DeskPhone $phone): RedirectResponse
    {
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
