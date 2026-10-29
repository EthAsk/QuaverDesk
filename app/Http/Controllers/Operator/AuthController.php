<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\Operator;
use App\Models\OperatorAudit;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use PragmaRX\Google2FA\Google2FA;

/**
 * Operator sign-in: password, then an authenticator code, always. An operator
 * without two-factor sets it up before they get in.
 */
class AuthController extends Controller
{
    /** How long the password step counts for while the code is entered. */
    private const PENDING_SECONDS = 600;

    public function __construct(private readonly Google2FA $google2fa)
    {
    }

    public function showLogin(): View
    {
        return view('operator.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        $operator = Operator::query()->firstWhere('email', strtolower($data['email']));

        if (! $operator || ! Auth::guard('operator')->getProvider()->validateCredentials($operator, ['password' => $data['password']])) {
            OperatorAudit::record('login.failed', details: ['email' => substr(strtolower($data['email']), 0, 255)]);
            throw ValidationException::withMessages(['email' => 'Those details don\'t match an operator account.']);
        }

        $request->session()->regenerate();
        $request->session()->put('operator.pending', ['id' => $operator->id, 'at' => time()]);

        return redirect()->route('operator.two-factor');
    }

    public function showTwoFactor(Request $request): View|RedirectResponse
    {
        $operator = $this->pending($request);
        if (! $operator) {
            return redirect()->route('operator.login');
        }
        if ($operator->hasTwoFactor()) {
            return view('operator.two-factor');
        }

        // First sign-in: set up an authenticator app.
        $secret = $request->session()->get('operator.setup_secret') ?: $this->google2fa->generateSecretKey(32);
        $request->session()->put('operator.setup_secret', $secret);
        $url = $this->google2fa->getQRCodeUrl(config('app.name').' operator', $operator->email, $secret);
        $qr = (new Writer(new ImageRenderer(new RendererStyle(192, 1), new SvgImageBackEnd())))->writeString($url);

        return view('operator.two-factor-setup', ['qr' => $qr, 'secret' => $secret]);
    }

    public function twoFactor(Request $request): RedirectResponse
    {
        $operator = $this->pending($request);
        if (! $operator) {
            return redirect()->route('operator.login');
        }
        $code = preg_replace('/\s+/', '', (string) $request->validate(['code' => ['required', 'string', 'max:10']])['code']);

        $setup = ! $operator->hasTwoFactor();
        $secret = $setup ? (string) $request->session()->get('operator.setup_secret') : (string) $operator->two_factor_secret;
        // verifyKeyNewer refuses a code from a time step already used, so a seen code can't be replayed.
        $step = $secret === '' ? false : $this->google2fa->verifyKeyNewer($secret, $code, (int) $operator->two_factor_last_step, 1);
        if (! is_int($step)) {
            OperatorAudit::record('login.failed', details: ['email' => $operator->email, 'step' => 'code']);
            throw ValidationException::withMessages(['code' => 'That code isn\'t right. Use the current code from your authenticator app.']);
        }

        if ($setup) {
            $operator->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now()]);
        }
        $operator->forceFill(['two_factor_last_step' => $step])->save();

        $request->session()->forget(['operator.pending', 'operator.setup_secret']);
        Auth::guard('operator')->login($operator);
        $request->session()->regenerate();
        OperatorAudit::record($setup ? 'login.two-factor-set-up' : 'login');

        return redirect()->intended(route('operator.companies'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('operator')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('operator.login');
    }

    private function pending(Request $request): ?Operator
    {
        $pending = $request->session()->get('operator.pending');
        if (! is_array($pending) || time() - (int) ($pending['at'] ?? 0) > self::PENDING_SECONDS) {
            return null;
        }

        return Operator::query()->find($pending['id'] ?? null);
    }
}
