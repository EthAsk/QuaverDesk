<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Telephony\Dialplan;
use App\Http\Controllers\Controller;
use App\Mail\UserInvited;
use App\Models\RingGroup;
use App\Models\User;
use App\Support\Invites;
use App\Licensing\Bands;
use App\Support\LicenceManager;
use App\Support\LicenceRenewal;
use App\Tenancy\TenantRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

class UserController extends Controller
{
    public function index(LicenceManager $licences): View
    {
        return view('admin.users', [
            'users' => User::query()->orderBy('name')->get(),
            'maxUsers' => $licences->current()->maxUsers(),
            'bandOffer' => Bands::upgradeOffer($licences->current()),
            'plan' => $licences->current()->plan,
        ]);
    }

    public function store(Request $request, LicenceManager $licences, LicenceRenewal $renewal): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            // Unique across every company, not just this one: sign-in and password
            // resets find a person by email alone.
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', Rule::in([User::ROLE_ADMIN, User::ROLE_USER])],
            'extension' => ['nullable', ...self::extensionRules()],
        ], self::EXTENSION_MESSAGES);

        $max = $licences->current()->maxUsers();

        // Full, and the admin agreed to move up a band: pay for it first, then add the person.
        $offer = Bands::upgradeOffer($licences->current());
        if ($offer && $request->boolean('upgrade') && User::query()->count() >= $max) {
            try {
                $max = $renewal->upgrade($offer['band'])->maxUsers();
            } catch (RuntimeException $e) {
                return back()->withInput()->withErrors(['email' => $e->getMessage()]);
            }
        }

        $user = DB::transaction(function () use ($data, $max) {
            if (User::query()->lockForUpdate()->count() >= $max) {
                return null;
            }

            // Nobody knows this password: the person sets their own from the emailed link.
            $user = new User(['name' => $data['name'], 'email' => strtolower($data['email']), 'password' => Str::password(32)]);
            $user->role = $data['role'];
            $user->extension = $data['extension'] ?? null; // blank: the next free one
            $user->save();

            return $user;
        });

        if ($user === null) {
            return back()->withErrors(['email' => "Your plan allows {$max} users. Upgrade or remove a user first."]);
        }

        $link = Invites::link($user);
        Mail::to($user)->queue(new UserInvited($user, $link, $request->user()->name));

        // Shown once too, in case the email doesn't arrive.
        return back()->with('invited', ['email' => $user->email, 'link' => $link]);
    }

    /** Email someone a fresh link to set their password (lost invite, forgotten password). Never shown here. */
    public function invite(Request $request, User $user): RedirectResponse
    {
        Mail::to($user)->queue(new UserInvited($user, Invites::link($user), $request->user()->name));

        return back()->with('status', "Sign-in link emailed to {$user->email}.");
    }

    /** Change the number colleagues dial to reach someone. */
    public function extension(Request $request, User $user): RedirectResponse
    {
        $data = $request->validateWithBag('extension', ['extension' => ['required', ...self::extensionRules($user)]], self::EXTENSION_MESSAGES);

        $user->extension = $data['extension'];
        $user->save();

        return back()->with('status', "{$user->name} is now on extension {$user->extension}.");
    }

    private const EXTENSION_MESSAGES = [
        'extension.regex' => 'Extensions are 3 to 5 digits, starting with 2 to 8 (so they never clash with 999 or other numbers).',
        'extension.unique' => 'Someone else already has that extension.',
        'extension.not_in' => 'A ring group already has that extension.',
    ];

    /** @return list<mixed> */
    private static function extensionRules(?User $except = null): array
    {
        return [
            'string',
            'regex:'.Dialplan::EXTENSION,
            TenantRule::unique('users', 'extension')->ignore($except?->id),
            Rule::notIn(RingGroup::query()->whereNotNull('extension')->pluck('extension')->all()),
        ];
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        abort_if($user->isOwner(), 403, 'The owner role cannot be changed here.');

        $data = $request->validate(['role' => ['required', Rule::in([User::ROLE_ADMIN, User::ROLE_USER])]]);
        $user->role = $data['role'];
        $user->save();

        return back()->with('status', "{$user->name} is now ".($user->isAdmin() ? 'an admin' : 'a user').'.');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        abort_if($user->isOwner() || $user->is($request->user()), 403);

        $user->delete();

        return back()->with('status', "{$user->name} was removed.");
    }
}
