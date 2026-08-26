<?php

declare(strict_types=1);

namespace App\Models;

/**
 * The one place score-to-outcome thresholds live.
 *
 * Students are never shown a raw score — only the band it falls into.
 * Two vocabularies are in play: examinations resolve to
 * fail/resubmit/pass/distinction, document reviews to
 * rejected/resubmit/valid. Both are driven off the same simple rule —
 * average the examiners' percentages, then look the average up here.
 *
 * These thresholds are policy, not mechanics. Change the two tables
 * below and every page that reports an outcome follows.
 */
class GradingPolicy
{
    /** @var array<int, array{0:float, 1:string, 2:string}> [minimum inclusive, outcome, label] */
    private const EXAM_BANDS = [
        [75.0, 'distinction', 'Distinction'],
        [50.0, 'pass',        'Pass'],
        [31.0, 'resubmit',    'Resubmit'],
        [0.0,  'fail',        'Fail'],
    ];

    /** @var array<int, array{0:float, 1:string, 2:string}> [minimum inclusive, outcome, label] */
    private const DOCUMENT_BANDS = [
        [50.0, 'valid',    'Valid'],
        [31.0, 'resubmit', 'Resubmit'],
        [0.0,  'rejected', 'Rejected'],
    ];

    /**
     * Letter grades are an internal/staff-facing detail — they stay out
     * of everything a student sees.
     *
     * @var array<int, array{0:float, 1:string}> [minimum inclusive, letter]
     */
    private const LETTER_BANDS = [
        [80.0, 'A'],
        [70.0, 'B'],
        [60.0, 'C'],
        [50.0, 'D'],
        [40.0, 'E'],
        [0.0,  'F'],
    ];

    /**
     * @return array{outcome:string, label:string}
     */
    public static function examOutcome(float $average): array
    {
        return self::resolve(self::EXAM_BANDS, $average);
    }

    /**
     * @return array{outcome:string, label:string}
     */
    public static function documentOutcome(float $average): array
    {
        return self::resolve(self::DOCUMENT_BANDS, $average);
    }

    public static function letterFor(float $average): string
    {
        foreach (self::LETTER_BANDS as [$min, $letter]) {
            if ($average >= $min) {
                return $letter;
            }
        }
        return 'F';
    }

    /**
     * Human-readable band ranges, for explaining the scale on a page
     * without duplicating the numbers in a template.
     *
     * @return array<int, array{label:string, range:string}>
     */
    public static function examScale(): array
    {
        return self::describe(self::EXAM_BANDS);
    }

    /**
     * @return array<int, array{label:string, range:string}>
     */
    public static function documentScale(): array
    {
        return self::describe(self::DOCUMENT_BANDS);
    }

    /**
     * Maps an outcome onto the stamp classes the stylesheets already
     * define, so outcome rendering stays consistent across pages.
     */
    public static function stampClass(string $outcome): string
    {
        return match ($outcome) {
            'distinction', 'pass', 'valid' => 'approved',
            'resubmit'                     => 'pending',
            default                        => 'rejected',
        };
    }

    /**
     * @param array<int, array{0:float, 1:string, 2:string}> $bands
     * @return array{outcome:string, label:string}
     */
    private static function resolve(array $bands, float $average): array
    {
        foreach ($bands as [$min, $outcome, $label]) {
            if ($average >= $min) {
                return ['outcome' => $outcome, 'label' => $label];
            }
        }

        $last = $bands[count($bands) - 1];
        return ['outcome' => $last[1], 'label' => $last[2]];
    }

    /**
     * @param array<int, array{0:float, 1:string, 2:string}> $bands
     * @return array<int, array{label:string, range:string}>
     */
    private static function describe(array $bands): array
    {
        $described = [];
        $upper = 100.0;

        foreach ($bands as [$min, , $label]) {
            $described[] = [
                'label' => $label,
                'range' => rtrim(rtrim(number_format($min, 1, '.', ''), '0'), '.')
                    . '–' . rtrim(rtrim(number_format($upper, 1, '.', ''), '0'), '.') . '%',
            ];
            $upper = $min - 1;
        }

        return $described;
    }
}
