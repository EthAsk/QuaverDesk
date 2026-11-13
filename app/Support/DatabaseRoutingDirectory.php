<?php

namespace App\Support;

use App\Domain\Calls\DialTarget;
use App\Domain\Calls\FollowMe;
use App\Domain\Telephony\DialDecision;
use App\Domain\Licensing\Feature;
use App\Domain\Routing\Destination;
use App\Domain\Routing\IvrConfig;
use App\Domain\Routing\MailboxConfig;
use App\Models\Mailbox;
use App\Models\Receptionist;
use App\Ai\AiSettings;
use App\Domain\Routing\ReceptionistConfig;
use App\Domain\Routing\RingGroupConfig;
use App\Domain\Routing\RoutingDirectory;
use App\Models\DeskPhone;
use App\Models\IvrMenu;
use App\Models\RingGroup;
use App\Models\SipTrunk;
use App\Models\User;

/**
 * Database-backed lookup for the router. Ring groups and IVR menus are paid
 * (Routing) features: without the licence they resolve to nothing, so the
 * caller hears the "unavailable" message instead of reaching them.
 */
class DatabaseRoutingDirectory implements RoutingDirectory
{
    public function __construct(
        private readonly LicenceManager $licences,
        private readonly CallLimits $limits,
    ) {
    }

    /**
     * Their phones, and their mobile if they turned on follow-me (calls to them,
     * not to their ring groups). Forward-only contacts have no phones: just the mobile.
     */
    public function userTargets(int $userId): array
    {
        $user = User::query()->with('deskPhones')->find($userId);
        if ($user === null) {
            return [];
        }

        $targets = $this->targetsFor($user);
        if ($mobile = $this->followMe($user)) {
            $targets[] = $mobile;
        }

        return $targets;
    }

    /**
     * A person's verified mobile, while it can be called: they can call phone
     * numbers, there's a line and a number to show, and the call is within the
     * toll-fraud limits (it is an outside call like any other).
     */
    private function followMe(User $user): ?DialTarget
    {
        if (! $user->followsMe()) {
            return null;
        }

        $trunk = SipTrunk::defaultOutbound();
        $callerId = CallerId::default();
        if ($trunk === null || $callerId === null || $this->limits->refusal($user, DialDecision::number($user->mobile)) !== null) {
            return null;
        }

        return DialTarget::mobile($user->mobile, $trunk->dialTrunk(), new FollowMe(
            $user->id,
            $callerId,
            $user->follow_me === User::FOLLOW_ME_AFTER ? $user->follow_me_delay : 0,
            $this->limits->maxSeconds($user, $user->mobile),
            $trunk->id,
        ));
    }

    /**
     * Softphone (unless desk phones only); desk phones and mobile apps too on
     * Plus once the desk phone service is set up. Never their mobile (see userTargets).
     *
     * @return list<DialTarget>
     */
    public function targetsFor(User $user): array
    {
        if ($user->isForwardOnly()) {
            return [];
        }

        // Desk-phone-only users have no softphone: just their desk phones.
        $targets = $user->isPhoneOnly() ? [] : [DialTarget::client($user->carrierIdentity())];

        if ($this->licences->allows(Feature::DeskPhones) && DeskPhone::service()) {
            foreach ($user->deskPhones as $phone) {
                if ($uri = $phone->sipUri()) {
                    $targets[] = DialTarget::sip($uri);
                }
            }
        }

        return $targets;
    }

    /** Their voicemail box, unless an admin turned their voicemail off (the box and its messages stay). */
    public function userMailbox(int $userId): ?int
    {
        return User::query()->whereKey($userId)->where('voicemail', true)->value('mailbox_id');
    }

    public function mailbox(int $id): ?MailboxConfig
    {
        $box = Mailbox::query()->with('greetingSound')->find($id);

        return $box ? new MailboxConfig($box->greeting, max(10, min(300, (int) $box->max_seconds)), $box->greetingSound?->url()) : null;
    }

    public function receptionist(int $id): ?ReceptionistConfig
    {
        $r = Receptionist::query()->find($id);
        if ($r === null) {
            return null;
        }

        $routes = [];
        foreach (array_slice((array) $r->routes, 0, Receptionist::MAX_ROUTES) as $route) {
            $to = is_array($route) ? Destination::parse($route['to'] ?? null) : null;
            if ($to !== null && filled($route['name'] ?? null)) {
                $routes[] = ['name' => (string) $route['name'], 'about' => (string) ($route['about'] ?? ''), 'to' => $to];
            }
        }
        $ai = app(AiSettings::class);

        return new ReceptionistConfig(
            business: (string) ($r->business ?: $r->name),
            greeting: $r->greeting,
            about: (string) $r->about,
            routes: $routes,
            mailboxId: $r->mailbox_id,
            fallback: Destination::parse($r->fallback),
            maxTurns: max(2, min(10, (int) $r->max_turns)),
            ready: $this->licences->allows(Feature::AiReceptionist) && $ai->transcriptionReady() && $ai->analysisReady(),
        );
    }

    public function ringGroup(int $id): ?RingGroupConfig
    {
        if (! $this->licences->allows(Feature::Routing)) {
            return null;
        }

        $group = RingGroup::query()->with('members.deskPhones')->find($id);
        if ($group === null) {
            return null;
        }

        return new RingGroupConfig(
            $group->strategy,
            max(5, min(120, (int) $group->ring_seconds)),
            $group->members->map(fn (User $u) => $this->targetsFor($u))->values()->all(),
            Destination::parse($group->fallback),
        );
    }

    public function ivr(int $id): ?IvrConfig
    {
        if (! $this->licences->allows(Feature::Routing)) {
            return null;
        }

        $menu = IvrMenu::query()->with('greetingSound')->find($id);
        if ($menu === null) {
            return null;
        }

        $options = [];
        foreach ((array) $menu->options as $digit => $value) {
            $dest = Destination::parse($value);
            if ($dest !== null && preg_match('/^[0-9]$/', (string) $digit)) {
                $options[(string) $digit] = $dest;
            }
        }

        return new IvrConfig(
            $menu->greeting,
            $options,
            max(3, min(20, (int) $menu->timeout_seconds)),
            Destination::parse($menu->fallback),
            $menu->greetingSound?->url(),
        );
    }
}
