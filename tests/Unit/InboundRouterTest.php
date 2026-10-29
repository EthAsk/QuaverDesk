<?php

namespace Tests\Unit;

use App\Domain\Calls\DialTarget;
use App\Domain\Routing\CallbackUrls;
use App\Domain\Routing\Destination;
use App\Domain\Routing\InboundRouter;
use App\Domain\Routing\IvrConfig;
use App\Domain\Routing\MailboxConfig;
use App\Domain\Routing\RingGroupConfig;
use App\Domain\Routing\RoutingContext;
use App\Domain\Routing\RoutingDirectory;
use App\Domain\Routing\WeeklySchedule;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class InboundRouterTest extends TestCase
{
    /**
     * @param  array<int, RingGroupConfig>  $groups
     * @param  array<int, IvrConfig>  $ivrs
     * @param  array<int, MailboxConfig>  $boxes
     * @param  array<int, int>  $userBoxes  user id => mailbox id
     */
    private function router(array $groups = [], array $ivrs = [], array $boxes = [], array $userBoxes = []): InboundRouter
    {
        $directory = new class($groups, $ivrs, $boxes, $userBoxes) implements RoutingDirectory {
            public function __construct(private array $groups, private array $ivrs, private array $boxes, private array $userBoxes) {}
            public function userTargets(int $userId): array {
                if ($userId === 404) { return []; }
                $t = [DialTarget::client("user-{$userId}")];
                if ($userId === 50) { $t[] = DialTarget::sip('sip:u50-desk@voice.test'); }
                return $t;
            }
            public function userMailbox(int $userId): ?int { return $this->userBoxes[$userId] ?? null; }
            public function ringGroup(int $id): ?RingGroupConfig { return $this->groups[$id] ?? null; }
            public function ivr(int $id): ?IvrConfig { return $this->ivrs[$id] ?? null; }
            public function mailbox(int $id): ?MailboxConfig { return $this->boxes[$id] ?? null; }
        };

        $urls = new class implements CallbackUrls {
            private function q(RoutingContext $c): string { return "hop={$c->hop}".($c->record ? '&rec=1' : ''); }
            public function route(Destination $d, RoutingContext $c): string { return "route?to={$d}&".$this->q($c); }
            public function afterDial(Destination $n, RoutingContext $c): string { return "after?next={$n}&".$this->q($c); }
            public function ivrChoice(Destination $i, RoutingContext $c): string { return "choice?ivr={$i}&".$this->q($c); }
            public function voicemailSaved(Destination $m): string { return "vm-saved?box={$m}"; }
            public function recordingSaved(): string { return 'rec-saved'; }
        };

        return new InboundRouter($directory, $urls);
    }

    private function ctx(int $hop = 0, bool $record = false): RoutingContext
    {
        return new RoutingContext($hop, $record);
    }

    public function test_destination_round_trips_and_rejects_junk(): void
    {
        $this->assertSame('ring_group:5:2', (string) Destination::parse('ring_group:5:2'));
        $this->assertSame('ivr:3', (string) Destination::parse('ivr:3'));
        $this->assertSame('voicemail:2', (string) Destination::parse('voicemail:2'));
        $this->assertSame('hangup', (string) Destination::parse('hangup'));
        $this->assertSame(null, Destination::parse('ivr'));
        $this->assertSame(null, Destination::parse('drop table;'));
        $this->assertSame(null, Destination::parse('user:0'));
    }

    public function test_user_destination_rings_their_softphone(): void
    {
        $steps = $this->router()->route(new Destination('user', 7), $this->ctx())->steps();

        $this->assertSame('dial', $steps[0][0]);
        $this->assertSame('user-7', $steps[0][1][0]->value);
        $this->assertSame('after?next=hangup&hop=0', $steps[0][2]['action']);
    }

    public function test_unanswered_user_goes_to_their_voicemail(): void
    {
        $router = $this->router([], [], [3 => new MailboxConfig('Leave a message.', 120)], [7 => 3]);

        $steps = $router->route(new Destination('user', 7), $this->ctx())->steps();
        $this->assertSame('after?next=voicemail:3&hop=0', $steps[0][2]['action']);

        $vm = $router->afterDial('no-answer', Destination::parse('voicemail:3'), $this->ctx())->steps();
        $this->assertSame(['say', 'Leave a message.'], $vm[0]);
        $this->assertSame('record', $vm[1][0]);
        $this->assertSame(120, $vm[1][1]['maxLength']);
        $this->assertSame('vm-saved?box=voicemail:3', $vm[1][1]['action']);
        $this->assertCount(2, $vm, 'the recording ends the script');
    }

    public function test_missing_user_says_unavailable(): void
    {
        $steps = $this->router()->route(new Destination('user', 404), $this->ctx())->steps();

        $this->assertSame(['say', InboundRouter::MSG_UNAVAILABLE], $steps[0]);
        $this->assertSame(['hangup'], $steps[1]);
    }

    public function test_simultaneous_group_rings_everyone_then_falls_back(): void
    {
        $router = $this->router([1 => new RingGroupConfig('simultaneous', 20, [[DialTarget::client('user-1')], [DialTarget::client('user-2')]], new Destination('ivr', 9))]);
        $steps = $router->route(new Destination('ring_group', 1), $this->ctx())->steps();

        $this->assertSame(2, count($steps[0][1]));
        $this->assertSame(20, $steps[0][2]['timeout']);
        $this->assertSame('after?next=ivr:9&hop=0', $steps[0][2]['action']);
    }

    public function test_in_order_group_rings_one_at_a_time(): void
    {
        $router = $this->router([1 => new RingGroupConfig('in_order', 15, [[DialTarget::client('user-1')], [DialTarget::client('user-2')]], null)]);

        $first = $router->route(new Destination('ring_group', 1), $this->ctx())->steps();
        $this->assertSame('user-1', $first[0][1][0]->value);
        $this->assertSame('after?next=ring_group:1:1&hop=0', $first[0][2]['action']);

        $second = $router->afterDial('no-answer', Destination::parse('ring_group:1:1'), $this->ctx())->steps();
        $this->assertSame('user-2', $second[0][1][0]->value);

        $done = $router->afterDial('busy', Destination::parse('ring_group:1:2'), $this->ctx(1))->steps();
        $this->assertSame(['say', InboundRouter::MSG_UNAVAILABLE], $done[0]);
    }

    public function test_answered_call_just_hangs_up_afterwards(): void
    {
        $this->assertSame([['hangup']], $this->router()->afterDial('completed', new Destination('user', 2), $this->ctx())->steps());
    }

    public function test_ivr_plays_menu_and_routes_choice(): void
    {
        $ivr = new IvrConfig('Press 1 for sales.', ['1' => new Destination('user', 3)], 6, null);
        $router = $this->router([], [4 => $ivr]);

        $steps = $router->route(new Destination('ivr', 4), $this->ctx())->steps();
        $this->assertSame('gather', $steps[0][0]);
        $this->assertSame('Press 1 for sales.', $steps[0][1]);
        $this->assertSame('choice?ivr=ivr:4&hop=0', $steps[0][2]['action']);
        $this->assertCount(1, $steps, 'gather ends the script; no key press posts to the action too');

        $silent = $router->ivrChoice(new Destination('ivr', 4), null, $this->ctx())->steps();
        $this->assertSame([['redirect', 'route?to=ivr:4:1&hop=1']], $silent);

        $chosen = $router->ivrChoice(new Destination('ivr', 4), '1', $this->ctx())->steps();
        $this->assertSame('user-3', $chosen[0][1][0]->value);

        $invalid = $router->ivrChoice(new Destination('ivr', 4), '7', $this->ctx())->steps();
        $this->assertSame(['say', InboundRouter::MSG_INVALID], $invalid[0]);
        $this->assertSame(['redirect', 'route?to=ivr:4:1&hop=1'], $invalid[1]);
    }

    public function test_ivr_gives_up_after_three_attempts(): void
    {
        $ivr = new IvrConfig('Menu', [], 5, new Destination('user', 8));
        $steps = $this->router([], [4 => $ivr])->route(new Destination('ivr', 4, 3), $this->ctx(3))->steps();

        $this->assertSame('user-8', $steps[0][1][0]->value);
    }

    public function test_self_referencing_ivr_cannot_loop_forever(): void
    {
        $ivr = new IvrConfig('Menu', ['1' => new Destination('ivr', 4)], 5, null);
        $steps = $this->router([], [4 => $ivr])->ivrChoice(new Destination('ivr', 4), '1', $this->ctx(InboundRouter::MAX_HOPS))->steps();

        $this->assertSame(['say', InboundRouter::MSG_UNAVAILABLE], $steps[0]);
    }

    public function test_out_of_hours_goes_to_closed_destination(): void
    {
        $hours = WeeklySchedule::fromArray(['days' => ['mon' => [['09:00', '17:00']]]]);
        $router = $this->router();

        $sundayNoon = new DateTimeImmutable('2026-11-01 12:00', new \DateTimeZone('Europe/London'));
        $steps = $router->forNumber(new Destination('user', 1), $hours, new Destination('user', 2), $sundayNoon)->steps();
        $this->assertSame('user-2', $steps[0][1][0]->value);

        $mondayTen = new DateTimeImmutable('2026-11-02 10:00', new \DateTimeZone('Europe/London'));
        $steps = $router->forNumber(new Destination('user', 1), $hours, new Destination('user', 2), $mondayTen)->steps();
        $this->assertSame('user-1', $steps[0][1][0]->value);
    }

    public function test_recorded_numbers_announce_first_and_keep_recording_through_callbacks(): void
    {
        $router = $this->router([1 => new RingGroupConfig('in_order', 15, [[DialTarget::client('user-1')], [DialTarget::client('user-2')]], null)]);

        $steps = $router->forNumber(new Destination('ring_group', 1), null, null, new DateTimeImmutable(), true, 'We record calls.')->steps();
        $this->assertSame(['say', 'We record calls.'], $steps[0]);
        $this->assertSame(true, $steps[1][2]['record']);
        $this->assertSame('rec-saved', $steps[1][2]['recordingStatusCallback']);
        $this->assertSame('after?next=ring_group:1:1&hop=0&rec=1', $steps[1][2]['action']);

        $next = $router->afterDial('no-answer', Destination::parse('ring_group:1:1'), $this->ctx(0, true))->steps();
        $this->assertSame(true, $next[0][2]['record']);
    }

    public function test_person_with_desk_phone_rings_both_devices(): void
    {
        $steps = $this->router()->route(new Destination('user', 50), $this->ctx())->steps();

        $this->assertSame(2, count($steps[0][1]));
        $this->assertSame('sip', $steps[0][1][1]->kind);
    }

    public function test_unrecorded_calls_have_no_recording_options(): void
    {
        $steps = $this->router()->route(new Destination('user', 1), $this->ctx())->steps();

        $this->assertFalse(isset($steps[0][2]['record']));
    }

    public function test_recorded_greetings_play_instead_of_the_text(): void
    {
        $router = $this->router(
            [],
            [4 => new IvrConfig('Press 1 for sales.', [], 6, null, 'https://app.test/menu.wav')],
            [3 => new MailboxConfig('Leave a message.', 120, 'https://app.test/vm.wav')],
        );

        $vm = $router->route(Destination::parse('voicemail:3'), $this->ctx())->steps();
        $this->assertSame(['play', 'https://app.test/vm.wav'], $vm[0]);
        $this->assertSame('record', $vm[1][0]);

        $menu = $router->route(new Destination('ivr', 4), $this->ctx())->steps();
        $this->assertSame('https://app.test/menu.wav', $menu[0][2]['sound']);
    }
}
