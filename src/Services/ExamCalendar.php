<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;

/**
 * Month grids for a student's exam calendar. Each day knows which of the
 * exam windows on offer it falls in, so the page can mark them without
 * doing date arithmetic in the template.
 */
class ExamCalendar
{
    /**
     * From this month to the month the last window closes, at most
     * $maxMonths of them. Weeks start on Monday; days outside the month
     * are blank.
     *
     * @param array<int, array<string, mixed>> $windows each with exam_schedule_id, starts_at, ends_at, tag
     * @return array<int, array{label: string, weeks: array<int, array<int, array<string, mixed>>>}>
     */
    public static function months(array $windows, DateTimeImmutable $today, int $maxMonths = 4): array
    {
        if ($windows === []) {
            return [];
        }

        $last = max(array_map(fn (array $w): string => substr($w['ends_at'], 0, 10), $windows));
        $first = $today->modify('first day of this month')->setTime(0, 0);
        $end = (new DateTimeImmutable($last))->modify('first day of this month');

        $months = [];
        for ($month = $first; $month <= $end && count($months) < $maxMonths; $month = $month->modify('+1 month')) {
            $months[] = [
                'label' => $month->format('F Y'),
                'weeks' => self::weeks($month, $windows, $today->format('Y-m-d')),
            ];
        }

        return $months;
    }

    /**
     * @param array<int, array<string, mixed>> $windows
     * @return array<int, array<int, array<string, mixed>>>
     */
    private static function weeks(DateTimeImmutable $month, array $windows, string $today): array
    {
        $days = [];
        // Blank cells before the 1st, Monday first.
        for ($i = 1; $i < (int) $month->format('N'); $i++) {
            $days[] = ['day' => null];
        }

        $daysInMonth = (int) $month->format('t');
        for ($d = 1; $d <= $daysInMonth; $d++) {
            $date = $month->setDate((int) $month->format('Y'), (int) $month->format('n'), $d)->format('Y-m-d');
            $days[] = [
                'day'     => $d,
                'date'    => $date,
                'today'   => $date === $today,
                'past'    => $date < $today,
                'windows' => array_values(array_filter(
                    $windows,
                    fn (array $w): bool => substr($w['starts_at'], 0, 10) <= $date && $date <= substr($w['ends_at'], 0, 10)
                )),
            ];
        }

        while (count($days) % 7 !== 0) {
            $days[] = ['day' => null];
        }

        return array_chunk($days, 7);
    }
}
