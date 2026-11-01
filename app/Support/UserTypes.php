<?php

namespace App\Support;

use App\Models\DeskPhoneEvent;
use App\Models\IvrMenu;
use App\Models\Mailbox;
use App\Models\Recording;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Moving people between a full account (sign-in, phones), desk phones only,
 * and a forward-only contact, and tidying up the voicemail boxes people leave behind.
 */
class UserTypes
{
    public function __construct(private readonly RecordingStore $recordings)
    {
    }

    /**
     * Everything that let them sign in or ring a phone goes: sessions, passkeys,
     * two-factor, reset links, desk phones, ring groups and the softphone password.
     * Their calls then ring their confirmed mobile, or go to their own voicemail.
     */
    public function makeForwardOnly(User $user): void
    {
        DB::transaction(function () use ($user) {
            $user->role = User::ROLE_USER;
            $user->forward_only = true;
            $user->phone_only = false;
            $user->forceFill([
                'two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null,
                'remember_token' => null,
                'follow_me' => $user->mobile ? User::FOLLOW_ME_TOGETHER : User::FOLLOW_ME_OFF, 'follow_me_delay' => 0,
            ])->save();
            $user->rotateSipSecret();

            foreach ($user->deskPhones()->get() as $phone) { // each one stops signing in
                DeskPhoneEvent::record(DeskPhoneEvent::REMOVED, $phone, auth()->user(), "{$user->name} was made forward-only.");
                $phone->delete();
            }
            DB::table('ring_group_user')->where('user_id', $user->id)->delete();
            DB::table('passkeys')->where('user_id', $user->id)->delete();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();

            // Their voicemail has to reach them by email.
            $box = $user->mailbox;
            if ($box === null || ! $box->members()->whereKey($user->id)->exists()) {
                $this->ownMailbox($user);
            } else {
                $box->forceFill(['attach_audio' => true])->save();
            }
        });
    }

    /**
     * Desk phones only: their sign-in ends (sessions, passkeys, two-factor,
     * reset links) and so does their softphone; their desk phones, ring groups
     * and voicemail stay. Takes one of the plan's users (the caller checks
     * there's room when they were forward-only).
     */
    public function makePhoneOnly(User $user): void
    {
        DB::transaction(function () use ($user) {
            $user->role = User::ROLE_USER;
            $user->forward_only = false;
            $user->phone_only = true;
            $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null, 'remember_token' => null])->save();
            $user->rotateSipSecret();

            DB::table('passkeys')->where('user_id', $user->id)->delete();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
        });
    }

    /** Back to a full account. Takes one of the plan's users; the caller checks there's room and sends the sign-in link. */
    public function giveSignIn(User $user, string $role): void
    {
        $user->role = $role;
        $user->forward_only = false;
        $user->phone_only = false;
        $user->save();
    }

    /** A box just for this person: their missed calls, emailed to them with the audio. */
    public function ownMailbox(User $user): Mailbox
    {
        $box = Mailbox::query()->create([
            'name' => $user->name,
            'greeting' => "You've reached {$user->name}. Please leave a message after the tone.",
            'attach_audio' => true,
        ]);
        $box->members()->attach($user);
        $user->mailbox()->associate($box)->save();

        return $box;
    }

    /** The boxes a person is in, or whose missed calls go to: the ones to check once they've gone. */
    public function mailboxesOf(User $user): array
    {
        return array_values(array_unique(array_filter([
            $user->mailbox_id,
            ...DB::table('mailbox_user')->where('user_id', $user->id)->pluck('mailbox_id')->all(),
        ])));
    }

    /**
     * Deletes boxes nobody receives any more (no members and no extra email
     * addresses), with their messages and phone-recorded greetings, so a
     * departed person's voicemail isn't kept. Boxes others still use stay.
     *
     * @param  list<int>  $ids
     * @return list<string> names of the boxes deleted
     */
    public function removeLeftoverMailboxes(array $ids): array
    {
        $deleted = [];
        foreach (Mailbox::query()->whereKey($ids)->withCount('members')->get() as $box) {
            if ($box->members_count > 0 || array_filter($box->extra_emails ?? []) !== []) {
                continue;
            }

            Recording::query()->where('mailbox_id', $box->id)->where('kind', Recording::VOICEMAIL)->each(function (Recording $r) {
                $this->recordings->deleteAudio($r);
                if ($r->upload_path) {
                    Storage::disk('local')->delete($r->upload_path);
                }
                $r->delete();
            });

            $sound = $box->greetingSound;
            $box->delete();
            // A greeting in their voice goes too, unless it's used somewhere else.
            if ($sound && ! Mailbox::query()->where('greeting_sound_id', $sound->id)->exists()
                && ! IvrMenu::query()->where('greeting_sound_id', $sound->id)->exists()
                && (string) Setting::get('sounds.hold_music') !== (string) $sound->id) {
                $sound->delete();
            }
            $deleted[] = $box->name;
        }

        return $deleted;
    }
}
