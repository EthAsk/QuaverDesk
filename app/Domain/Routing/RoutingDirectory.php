<?php

namespace App\Domain\Routing;

use App\Domain\Calls\DialTarget;

/** Read-only lookup the router uses; the app implements it over the database. */
interface RoutingDirectory
{
    /**
     * Every device that should ring for this person (softphone, plus desk
     * phones on Pro). Empty if the person no longer exists.
     *
     * @return list<DialTarget>
     */
    public function userTargets(int $userId): array;

    /** The voicemail box a user's unanswered calls go to, if any. */
    public function userMailbox(int $userId): ?int;

    public function ringGroup(int $id): ?RingGroupConfig;

    public function ivr(int $id): ?IvrConfig;

    public function mailbox(int $id): ?MailboxConfig;
}
