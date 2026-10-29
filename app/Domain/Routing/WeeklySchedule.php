<?php

namespace App\Domain\Routing;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Opening hours. Shape (stored as JSON):
 *   ['timezone' => 'Europe/London',
 *    'days' => ['mon' => [['09:00', '17:30']], 'sat' => [], …],
 *    'closed_dates' => ['2026-12-25', …]]
 *
 * Times are local wall-clock times, so the schedule follows British Summer
 * Time automatically. An interval's end is exclusive (17:30 means closed at 17:30).
 */
final class WeeklySchedule
{
    private const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    /** @param array<string, mixed> $data */
    private function __construct(private readonly array $data)
    {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $tz = $data['timezone'] ?? 'Europe/London';
        if (! in_array($tz, DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidArgumentException("Unknown timezone: {$tz}");
        }

        $days = [];
        foreach (self::DAYS as $day) {
            $days[$day] = [];
            foreach ($data['days'][$day] ?? [] as $interval) {
                [$open, $close] = array_values($interval) + [null, null];
                if (! self::validTime($open) || ! self::validTime($close) || $open >= $close) {
                    throw new InvalidArgumentException("Invalid hours for {$day}: {$open}–{$close}");
                }
                $days[$day][] = [$open, $close];
            }
        }

        $closed = array_values(array_filter(
            $data['closed_dates'] ?? [],
            fn ($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d),
        ));

        return new self(['timezone' => $tz, 'days' => $days, 'closed_dates' => $closed]);
    }

    public function isOpen(DateTimeImmutable $at): bool
    {
        $local = $at->setTimezone(new DateTimeZone($this->data['timezone']));

        if (in_array($local->format('Y-m-d'), $this->data['closed_dates'], true)) {
            return false;
        }

        $day = strtolower($local->format('D'));
        $time = $local->format('H:i');

        foreach ($this->data['days'][$day] as [$open, $close]) {
            if ($time >= $open && $time < $close) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->data;
    }

    private static function validTime(mixed $t): bool
    {
        return is_string($t) && (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d$|^24:00$/', $t);
    }
}
