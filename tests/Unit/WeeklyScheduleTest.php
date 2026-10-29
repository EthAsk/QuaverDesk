<?php

namespace Tests\Unit;

use App\Domain\Routing\WeeklySchedule;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class WeeklyScheduleTest extends TestCase
{
    private function officeHours(): WeeklySchedule
    {
        $weekday = [['09:00', '17:30']];

        return WeeklySchedule::fromArray([
            'timezone' => 'Europe/London',
            'days' => ['mon' => $weekday, 'tue' => $weekday, 'wed' => $weekday, 'thu' => $weekday, 'fri' => $weekday],
            'closed_dates' => ['2026-12-25'],
        ]);
    }

    public function test_open_during_hours_and_closed_outside(): void
    {
        $s = $this->officeHours();

        $this->assertTrue($s->isOpen(new DateTimeImmutable('2026-10-28 09:00', new \DateTimeZone('Europe/London'))));
        $this->assertTrue($s->isOpen(new DateTimeImmutable('2026-10-28 17:29', new \DateTimeZone('Europe/London'))));
        $this->assertFalse($s->isOpen(new DateTimeImmutable('2026-10-28 17:30', new \DateTimeZone('Europe/London'))));
        $this->assertFalse($s->isOpen(new DateTimeImmutable('2026-10-28 08:59', new \DateTimeZone('Europe/London'))));
        $this->assertFalse($s->isOpen(new DateTimeImmutable('2026-10-31 12:00', new \DateTimeZone('Europe/London'))), 'Saturday');
    }

    public function test_uses_uk_local_time_across_summer_time(): void
    {
        $s = $this->officeHours();

        // 08:30 UTC on a July Wednesday is 09:30 BST: open.
        $this->assertTrue($s->isOpen(new DateTimeImmutable('2026-07-15 08:30:00+00:00')));
        // 08:30 UTC on a December Wednesday is 08:30 GMT: closed.
        $this->assertFalse($s->isOpen(new DateTimeImmutable('2026-12-16 08:30:00+00:00')));
    }

    public function test_closed_dates_override_hours(): void
    {
        $this->assertFalse($this->officeHours()->isOpen(new DateTimeImmutable('2026-12-25 11:00', new \DateTimeZone('Europe/London'))));
    }

    public function test_rejects_bad_hours(): void
    {
        $this->expectException(InvalidArgumentException::class);
        WeeklySchedule::fromArray(['days' => ['mon' => [['17:00', '09:00']]]]);
    }

    public function test_rejects_unknown_timezone(): void
    {
        $this->expectException(InvalidArgumentException::class);
        WeeklySchedule::fromArray(['timezone' => 'Mars/Olympus']);
    }
}
