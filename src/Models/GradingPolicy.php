<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

/**
 * The one place score-to-outcome thresholds live.
 *
 * Students are never shown a raw score — only the band it falls into.
 * Two vocabularies are in play: examinations resolve to
 * fail/resubmit/pass/distinction, document reviews to
 * rejected/resubmit/valid.
 *
 * The exam vocabulary is admin-configurable, stored in `grading_bands`
 * (see GradingBand for its CRUD) — examOutcome()/examScale() read it
 * directly. The document vocabulary is a separate, unrelated concern
 * that stays a fixed policy here.
 */
class GradingPolicy
{
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
     * Bands an exam average against the admin-configurable
     * grading_bands table. Falls back to 'fail' if the bands are
     * missing/misconfigured (e.g. gaps in coverage) rather than
     * throwing — a missing band must never block scoring from working.
     *
     * @return array{outcome:string, label:string}
     */
    public static function examOutcome(PDO $db, float $average): array
    {
        $stmt = $db->prepare(
            "SELECT outcome FROM grading_bands WHERE :avg BETWEEN min_score AND max_score
             ORDER BY min_score DESC LIMIT 1"
        );
        $stmt->execute(['avg' => $average]);
        $outcome = $stmt->fetchColumn();

        if (!$outcome) {
            return ['outcome' => 'fail', 'label' => 'Fail'];
        }

        return ['outcome' => $outcome, 'label' => ucfirst($outcome)];
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
    public static function examScale(PDO $db): array
    {
        $stmt = $db->query("SELECT min_score, max_score, outcome FROM grading_bands ORDER BY min_score DESC");
        $described = [];

        foreach ($stmt->fetchAll() as $row) {
            $described[] = [
                'label' => ucfirst($row['outcome']),
                'range' => self::trimNumber((float) $row['min_score']) . '–' . self::trimNumber((float) $row['max_score']) . '%',
            ];
        }

        return $described;
    }

    private static function trimNumber(float $n): string
    {
        return rtrim(rtrim(number_format($n, 1, '.', ''), '0'), '.');
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
