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
use App\Support\MobileConfirmation;
use App\Support\UserTypes;
use App\Tenancy\TenantRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;

class UserController extends Controller
{
    public function index(LicenceManager $licences): View
    {
        return view('admin.users', [
            'users' => User::query()->orderBy('name')->get(),
            'seated' => User::query()->seated()->count(),
            'maxUsers' => $licences->current()->maxUsers(),
            'bandOffer' => Bands::upgradeOffer($licences->current()),
            'plan' => $licences->current()->plan,
        ]);
    }

    public function store(Request $request, LicenceManager $licences, LicenceRenewal $renewal, MobileConfirmation $confirmation): RedirectResponse
    {
        if ($request->input('role') === self::FORWARD_ONLY) {
            return $this->storeForwardOnly($request, $confirmation);
        }

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
        if ($offer && $request->boolean('upgrade') && User::query()->seated()->count() >= $max) {
            try {
                $max = $renewal->upgrade($offer['band'])->maxUsers();
            } catch (RuntimeException $e) {
                return back()->withInput()->withErrors(['email' => $e->getMessage()]);
            }
        }

        $user = DB::transaction(function () use ($data, $max) {
            if (User::query()->seated()->lockForUpdate()->count() >= $max) {
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

    private const FORWARD_ONLY = 'forward_only';

    /**
     * A forward-only contact: no sign-in or phones, and free. Calls ring their
     * mobile once they confirm it (we call it now), otherwise go to their own
     * voicemail box, which emails them the message.
     */
    private function storeForwardOnly(Request $request, MobileConfirmation $confirmation): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'mobile' => ['nullable', 'string', 'max:20'],
            'extension' => ['nullable', ...self::extensionRules()],
        ], self::EXTENSION_MESSAGES);
        $mobile = $this->mobileFrom($data);

        $user = DB::transaction(function () use ($data, $mobile) {
            // Nobody knows this password, and it is never used: these accounts can't sign in.
            $user = new User(['name' => $data['name'], 'email' => strtolower($data['email']), 'password' => Str::password(32)]);
            $user->role = User::ROLE_USER;
            $user->forward_only = true;
            $user->extension = $data['extension'] ?? null;
            $user->forceFill(['mobile' => $mobile, 'follow_me' => $mobile ? User::FOLLOW_ME_TOGETHER : User::FOLLOW_ME_OFF, 'follow_me_delay' => 0]);
            $user->save();

            // They can't sign in to play messages, so the audio comes with the email.
            app(UserTypes::class)->ownMailbox($user);

            return $user;
        });

        return back()->with('status', $this->confirming($user, $confirmation, "{$user->name} was added as a forward-only contact on extension {$user->extension}."));
    }

    /** A forward-only contact's mobile: changing it means confirming it again. Removing it sends their calls to voicemail. */
    public function mobile(Request $request, User $user, MobileConfirmation $confirmation): RedirectResponse
    {
        abort_unless($user->isForwardOnly(), 404); // people with a sign-in set their own, under Phone

        $mobile = $this->mobileFrom($request->validateWithBag('mobile', ['mobile' => ['nullable', 'string', 'max:20']]), 'mobile');
        if ($mobile !== $user->mobile) {
            $user->forceFill(['mobile' => $mobile, 'mobile_verified_at' => null, 'follow_me' => $mobile ? User::FOLLOW_ME_TOGETHER : User::FOLLOW_ME_OFF])->save();
        }

        return back()->with('status', match (true) {
            $mobile === null => "{$user->name}'s calls now go straight to their voicemail.",
            $user->mobile_verified_at !== null => 'Saved.',
            default => $this->confirming($user, $confirmation, 'Saved.'),
        });
    }

    /** Calls a forward-only contact's mobile again so they can press 1. */
    public function confirmMobile(User $user, MobileConfirmation $confirmation): RedirectResponse
    {
        abort_unless($user->isForwardOnly() && $user->mobile !== null && $user->mobile_verified_at === null, 404);

        return back()->with('status', $this->confirming($user, $confirmation, ''));
    }

    private function confirming(User $user, MobileConfirmation $confirmation, string $done): string
    {
        if ($user->mobile === null) {
            return trim($done.' Their calls go to their voicemail, which is emailed to them.');
        }
        $problem = $confirmation->call($user);

        return trim($done.' '.($problem === null
            ? "Calling {$user->name}'s mobile now: they press 1 to confirm it. Until then their calls go to voicemail."
            : "Their mobile isn't confirmed yet, so calls go to voicemail for now. {$problem}"));
    }

    /** @param array<string, mixed> $data */
    private function mobileFrom(array $data, string $bag = 'default'): ?string
    {
        if (! filled($data['mobile'] ?? null)) {
            return null;
        }

        return MobileConfirmation::normalise((string) $data['mobile'])
            ?? throw ValidationException::withMessages(['mobile' => 'Enter a UK mobile number, such as 07700 900123.'])->errorBag($bag);
    }

    /**
     * Switch someone between a full account and a forward-only contact. Going
     * forward-only ends their sign-in and removes their phones; getting a
     * sign-in back takes one of the plan's users and emails them a link.
     */
    public function type(Request $request, User $user, UserTypes $types, LicenceManager $licences, MobileConfirmation $confirmation): RedirectResponse
    {
        abort_if($user->isOwner() || $user->is($request->user()), 403);
        $data = $request->validate(['type' => ['required', Rule::in([self::FORWARD_ONLY, User::ROLE_USER, User::ROLE_ADMIN])]]);

        if ($data['type'] === self::FORWARD_ONLY) {
            abort_if($user->isForwardOnly(), 404);
            $types->makeForwardOnly($user);
            $done = "{$user->name} is now a forward-only contact: signed out, with their phones removed.";

            return back()->with('status', $user->mobile_verified_at !== null
                ? $done.' Their calls now ring their mobile.'
                : $this->confirming($user, $confirmation, $done));
        }

        abort_unless($user->isForwardOnly(), 404);
        $max = $licences->current()->maxUsers();
        $added = DB::transaction(function () use ($user, $types, $data, $max) {
            if (User::query()->seated()->lockForUpdate()->count() >= $max) {
                return false;
            }
            $types->giveSignIn($user, $data['type']);

            return true;
        });
        if (! $added) {
            return back()->withErrors(['type' => "Your plan allows {$max} users. Upgrade or remove a user first."], 'type');
        }

        $link = Invites::link($user);
        Mail::to($user)->queue(new UserInvited($user, $link, $request->user()->name));

        return back()->with('invited', ['email' => $user->email, 'link' => $link]);
    }

    /** Email someone a fresh link to set their password (lost invite, forgotten password). Never shown here. */
    public function invite(Request $request, User $user): RedirectResponse
    {
        abort_if($user->isForwardOnly(), 404);

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
        abort_if($user->isForwardOnly(), 404);

        $data = $request->validate(['role' => ['required', Rule::in([User::ROLE_ADMIN, User::ROLE_USER])]]);
        $user->role = $data['role'];
        $user->save();

        return back()->with('status', "{$user->name} is now ".($user->isAdmin() ? 'an admin' : 'a user').'.');
    }

    public function destroy(Request $request, User $user, UserTypes $types): RedirectResponse
    {
        abort_if($user->isOwner() || $user->is($request->user()), 403);

        // Their voicemail goes with them, unless a box still reaches someone else.
        $removed = DB::transaction(function () use ($user, $types) {
            $boxes = $types->mailboxesOf($user);
            $user->delete();

            return $types->removeLeftoverMailboxes($boxes);
        });

        return back()->with('status', "{$user->name} was removed.".($removed === [] ? '' : ' Their voicemail box ('.implode(', ', $removed).') and its messages were deleted too.'));
    }
}
