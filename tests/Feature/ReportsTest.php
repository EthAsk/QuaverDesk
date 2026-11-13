<?php

namespace Tests\Feature;

use App\Domain\Licensing\Plan;
use App\Mail\ReportEmail;
use App\Models\Call;
use App\Models\CallQueue;
use App\Models\QueueCaller;
use App\Models\Setting;
use App\Models\User;
use App\Support\Reports;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Reporting and export (Plus; queue and agent reports Pro, #112). */
class ReportsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->owner()->withTwoFactor()->create(['name' => 'Alex Owner']);
        $this->acknowledgeTerms($this->owner);
    }

    private function makeCall(string $when, array $a): Call
    {
        $call = Call::create($a + ['carrier_sid' => 'sid-'.uniqid(), 'direction' => 'inbound', 'from' => '+447700900123', 'to' => '+442079460000', 'status' => 'completed', 'duration_seconds' => 60]);
        $call->forceFill(['created_at' => CarbonImmutable::parse($when, 'UTC')])->save();

        return $call;
    }

    public function test_free_has_no_reports_plus_has_the_overview_and_pro_the_queue_and_people_reports(): void
    {
        $this->actingAs($this->owner)->get('/admin/reports')->assertRedirect('/dashboard');

        $this->usePlan(Plan::Plus);
        $this->get('/admin/reports')->assertOk()->assertSee('Summary')->assertSee('Queue and agent reports')->assertDontSee('Service level is');
        $this->post('/admin/reports/schedule', ['schedule' => 'weekly'])->assertRedirect('/dashboard');

        $this->usePlan(Plan::Pro);
        $this->get('/admin/reports')->assertOk()->assertSee('Service level is')->assertSee('People');
    }

    public function test_the_overview_counts_calls_in_uk_time(): void
    {
        $this->usePlan(Plan::Plus);
        $in = fn (string $t, array $a = []) => $this->makeCall($t, ['direction' => 'inbound'] + $a);
        // 23:30 UTC on 1 June is 00:30 on 2 June in the UK (summer time).
        $in('2026-06-01 23:30:00');
        $in('2026-06-02 08:05:00', ['status' => 'no-answer', 'duration_seconds' => 12]);
        $this->makeCall('2026-06-02 09:00:00', ['direction' => 'outbound', 'to' => '+447700900555', 'duration_seconds' => 120]);
        $this->makeCall('2026-06-02 09:30:00', ['direction' => 'internal', 'from' => '201', 'to' => '202']);
        $this->makeCall('2026-06-02 09:40:00', ['direction' => 'inbound', 'leg' => Call::LEG_TRANSFER]); // not a call of its own
        $this->makeCall('2026-06-05 09:00:00', ['direction' => 'inbound']); // outside the range

        $o = (new Reports('2026-06-01', '2026-06-04'))->overview();

        $this->assertSame(2, $o['summary']['inbound']);
        $this->assertSame(1, $o['summary']['outbound']);
        $this->assertSame(1, $o['summary']['internal']);
        $this->assertSame(1, $o['summary']['missed']);
        $this->assertSame(50, $o['summary']['answer_rate']);
        $this->assertSame(240, $o['summary']['talk_seconds']); // 60 + 120 + the internal call's 60
        $this->assertSame(['inbound' => 0, 'outbound' => 0, 'missed' => 0], $o['days']['2026-06-01']);
        $this->assertSame(['inbound' => 2, 'outbound' => 1, 'missed' => 1], $o['days']['2026-06-02']);
        $this->assertSame(1, $o['hours'][0]); // 00:30 UK
        $this->assertSame(1, $o['hours'][9]); // 08:05 UTC is 09:05 UK
    }

    public function test_queue_report_counts_outcomes_waits_and_service_level(): void
    {
        $this->usePlan(Plan::Pro);
        $q = CallQueue::create(['name' => 'Support']);
        $mk = function (string $outcome, int $wait) use ($q) {
            $at = CarbonImmutable::parse('2026-06-02 10:00:00', 'UTC');
            QueueCaller::create(['call_queue_id' => $q->id, 'carrier_sid' => 'q-'.uniqid(), 'from' => '+447700900123', 'joined_at' => $at, 'seen_at' => $at,
                'ended_at' => $at->addSeconds($wait), 'outcome' => $outcome]);
        };
        $mk(QueueCaller::ANSWERED, 10);
        $mk(QueueCaller::ANSWERED, 50);
        $mk(QueueCaller::ABANDONED, 90);
        $mk(QueueCaller::OVERFLOW, 300);

        $row = (new Reports('2026-06-02', '2026-06-02'))->queues()[0];

        $this->assertSame(['Support', 4, 2, 1, 1], [$row['name'], $row['offered'], $row['answered'], $row['abandoned'], $row['overflowed']]);
        $this->assertSame(113, $row['avg_wait']); // (10 + 50 + 90 + 300) / 4 = 112.5, rounded
        $this->assertSame(300, $row['longest_wait']);
        $this->assertSame(25, $row['service_level']); // only the 10 second answer was inside 30 seconds
    }

    public function test_people_report(): void
    {
        $this->usePlan(Plan::Pro);
        $ann = User::factory()->create(['name' => 'Ann Agent']);
        $this->makeCall('2026-06-02 10:00:00', ['user_id' => $ann->id, 'duration_seconds' => 100]);
        $this->makeCall('2026-06-02 10:10:00', ['user_id' => $ann->id, 'status' => 'no-answer', 'duration_seconds' => 8]);
        $this->makeCall('2026-06-02 10:20:00', ['user_id' => $ann->id, 'direction' => 'outbound', 'duration_seconds' => 40]);

        $a = (new Reports('2026-06-02', '2026-06-02'))->agents();

        $this->assertSame([['name' => 'Ann Agent', 'taken' => 1, 'made' => 1, 'missed' => 1, 'talk_seconds' => 140]], $a);
    }

    public function test_the_csv_holds_the_calls_and_defuses_spreadsheet_formulas(): void
    {
        $this->usePlan(Plan::Plus);
        $this->makeCall(now()->toDateTimeString(), ['caller_name' => '=HYPERLINK("http://evil.example","click")', 'user_id' => $this->owner->id]);
        $this->makeCall(now()->toDateTimeString(), ['direction' => 'outbound', 'to' => '+447700900555']);

        $csv = $this->actingAs($this->owner)->get('/admin/reports/calls.csv')->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->streamedContent();
        $rows = array_map('str_getcsv', array_filter(explode("\n", $csv)));

        $this->assertSame('Started (UK time)', $rows[0][0]);
        $this->assertCount(3, $rows);
        $this->assertStringStartsWith("'=HYPERLINK", $rows[1][4]);
        $this->assertSame('Alex Owner', $rows[1][5]);

        $only = $this->get('/admin/reports/calls.csv?direction=outbound')->streamedContent();
        $this->assertCount(2, array_filter(explode("\n", $only)));
        $this->get('/admin/reports/calls.csv?from=not-a-date')->assertSessionHasErrors('from');
    }

    public function test_a_range_is_limited_to_a_year(): void
    {
        $this->usePlan(Plan::Plus);

        $page = $this->actingAs($this->owner)->get('/admin/reports?from=2020-01-01&to=2026-06-30')->assertOk();

        $this->assertCount(Reports::MAX_DAYS, $page->viewData('overview')['days']);
    }

    public function test_the_weekly_report_is_emailed_to_admins_on_mondays_only_when_asked_and_on_pro(): void
    {
        Mail::fake();
        $this->usePlan(Plan::Pro);
        $admin = User::factory()->create(['email' => 'admin@example.co.uk']);
        $admin->role = User::ROLE_ADMIN;
        $admin->save();
        User::factory()->create(['email' => 'plain@example.co.uk']);
        $this->makeCall('2026-11-03 10:00:00', ['duration_seconds' => 30]);

        $this->travelTo(CarbonImmutable::parse('2026-11-09 07:00:00', Reports::TZ)); // a Monday
        $this->artisan('reports:send')->assertSuccessful();
        Mail::assertNothingQueued(); // not asked for

        Setting::put('reports.schedule', 'weekly');
        $this->artisan('reports:send')->assertSuccessful();
        Mail::assertQueued(ReportEmail::class, 2); // the owner and the admin, not the plain user
        Mail::assertQueued(ReportEmail::class, fn (ReportEmail $m) => $m->hasTo('admin@example.co.uk') && $m->summary['inbound'] === 1 && str_contains($m->csv, '+447700900123'));

        $this->travelTo(CarbonImmutable::parse('2026-11-10 07:00:00', Reports::TZ)); // a Tuesday
        Mail::fake();
        $this->artisan('reports:send');
        Mail::assertNothingQueued();

        $this->usePlan(Plan::Plus);
        $this->travelTo(CarbonImmutable::parse('2026-11-16 07:00:00', Reports::TZ));
        $this->artisan('reports:send');
        Mail::assertNothingQueued();
    }

    public function test_an_admin_sets_the_schedule(): void
    {
        $this->usePlan(Plan::Pro);

        $this->actingAs($this->owner)->post('/admin/reports/schedule', ['schedule' => 'monthly'])->assertRedirect();
        $this->assertSame('monthly', Setting::get('reports.schedule'));
        $this->post('/admin/reports/schedule', ['schedule' => 'daily'])->assertSessionHasErrors('schedule');
    }
}
