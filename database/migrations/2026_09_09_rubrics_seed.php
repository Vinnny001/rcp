<?php

/**
 * Seeds the two marking schemes, transcribed from the JKUAT forms.
 *
 * Written as PHP rather than SQL because the criteria are long, order
 * matters, and re-running has to be safe — a seed that duplicates 30
 * criteria on a second run would be worse than no seed at all.
 *
 * Table 1's criteria sum to 45. The printed form's TOTAL row says 40,
 * which is an error in the source document: the six sections add up to
 * 10+10+5+5+5+10. The criteria are transcribed as printed and the
 * maximum is derived from them, so the total can no longer disagree
 * with the rows above it. Confirmed with the client on 2026-09-09.
 *
 * Run: php database/migrations/2026_09_09_rubrics_seed.php
 */

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

$env = parse_ini_file(__DIR__ . '/../../.env');
$pdo = new PDO(
    "mysql:host={$env['DB_HOST']};dbname={$env['DB_NAME']};charset=utf8mb4",
    $env['DB_USER'],
    $env['DB_PASS'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

/** Oral Presentation of Project Proposal — 6 sections, 16 criteria, 45 marks. */
$oralPresentation = [
    ['Problem and its Settings', 'Is the problem well focused?', 2],
    ['Problem and its Settings', 'Is the problem important or likely to yield "new" information?', 3],
    ['Problem and its Settings', 'Are the objectives actionable, time-bound, measurable and realistic?', 3],
    ['Problem and its Settings', 'Are the research questions well defined?', 2],
    ['Research Design and Methodology', 'Is the description of the research design well focused?', 3],
    ['Research Design and Methodology', 'Is the data easy to collect and relevant to the problem at hand?', 2],
    ['Research Design and Methodology', 'Are the proposed methods/techniques/processes/measuring instruments/procedures/software/hardware appropriate for the problem at hand?', 3],
    ['Research Design and Methodology', 'Are there appropriate and adequate control?', 2],
    ['Literature Review', 'Is the literature review relevant to the problem?', 5],
    ['Schedule and Gantt Chart', 'Is there sufficient time devoted to each activity?', 5],
    ['Budget and Budget Justification', 'Is the budget accurate, realistic and well formatted?', 5],
    ['Presentation', 'Are the slides well structured and information has not been lifted from the report?', 2],
    ['Presentation', 'The slides are not crammed with too much information and too small to read', 2],
    ['Presentation', 'There is no contradiction between written and verbal materials', 2],
    ['Presentation', 'Time is well managed', 2],
    ['Presentation', 'Questions are answered to acceptable standards and speaker is confident and enthusiastic in voice and posture', 2],
];

/** Examiner's Thesis Marking Scheme — 14 sections, 100 marks. */
$thesisMarking = [
    ['Abstract', 5],
    ['Background', 5],
    ['Problem Statement', 5],
    ['Rationale', 2],
    ['Research Questions and Objectives', 5],
    ['Literature Review', 15],
    ['Materials and Methods', 10],
    ['Results', 15],
    ['Discussion', 15],
    ['Conclusion', 5],
    ['Recommendations', 3],
    ['Originality of Contribution', 5],
    ['Literature Citation', 5],
    ['Overall Presentation', 5],
];

/** The five interpretation bands printed on Table 2. */
$thesisBands = [
    [70, 'Passes — typographical corrections', 'Passes subject to typographical corrections as indicated on a separate report.'],
    [60, 'Passes — minor changes', 'Passes subject to minor changes (editorial/statistical corrections).'],
    [50, 'Passes — major corrections', 'Passes subject to major corrections and revision as detailed on a separate report.'],
    [40, 'Not accepted — resubmit', 'Not accepted but may be re-submitted after the improvements indicated.'],
    [0,  'Rejected outright', 'Dissertation is rejected outright; reasons given on a separate report.'],
];

function uuid(PDO $pdo): string
{
    return (string) $pdo->query('SELECT UUID()')->fetchColumn();
}

function ensureTemplate(PDO $pdo, string $code, string $name, bool $hasPanelLeader): string
{
    $stmt = $pdo->prepare("SELECT template_id FROM rubric_templates WHERE code = ? LIMIT 1");
    $stmt->execute([$code]);
    if ($existing = $stmt->fetchColumn()) {
        return (string) $existing;
    }

    $id = uuid($pdo);
    $pdo->prepare(
        "INSERT INTO rubric_templates (template_id, code, name, has_panel_leader) VALUES (?, ?, ?, ?)"
    )->execute([$id, $code, $name, $hasPanelLeader ? 1 : 0]);

    return $id;
}

/**
 * @param array<int, array{0:string, 1:string, 2:int|float}> $criteria
 */
function seedCriteria(PDO $pdo, string $templateId, array $criteria): int
{
    $count = (int) $pdo->query("SELECT COUNT(*) FROM rubric_criteria WHERE template_id = '$templateId'")->fetchColumn();
    if ($count > 0) {
        return 0; // already seeded; leave any edits alone
    }

    $insert = $pdo->prepare(
        "INSERT INTO rubric_criteria (criterion_id, template_id, section_name, criterion_text, max_score, display_order)
         VALUES (UUID(), ?, ?, ?, ?, ?)"
    );
    foreach ($criteria as $i => [$section, $text, $max]) {
        $insert->execute([$templateId, $section, $text, $max, $i + 1]);
    }

    return count($criteria);
}

$pdo->beginTransaction();

$oralId = ensureTemplate($pdo, 'oral_proposal_presentation', 'Oral Presentation of Project Proposal', true);
$added1 = seedCriteria($pdo, $oralId, $oralPresentation);

$thesisId = ensureTemplate($pdo, 'thesis_marking_scheme', "Examiner's Thesis Marking Scheme", false);
$added2 = seedCriteria($pdo, $thesisId, array_map(
    static fn (array $row): array => [$row[0], $row[0], $row[1]],
    $thesisMarking
));

$bandCount = (int) $pdo->query("SELECT COUNT(*) FROM rubric_template_bands WHERE template_id = '$thesisId'")->fetchColumn();
$addedBands = 0;
if ($bandCount === 0) {
    $insert = $pdo->prepare(
        "INSERT INTO rubric_template_bands (band_id, template_id, min_percentage, label, description)
         VALUES (UUID(), ?, ?, ?, ?)"
    );
    foreach ($thesisBands as [$min, $label, $description]) {
        $insert->execute([$thesisId, $min, $label, $description]);
        $addedBands++;
    }
}

// Point the configured exam stages at the scheme each is marked with.
$link = $pdo->prepare("UPDATE exam_stages SET rubric_template_id = ? WHERE code = ? AND rubric_template_id IS NULL");
foreach (['approval' => $oralId, 'concept_presentation' => $oralId,
          'thesis_draft' => $thesisId, 'final_thesis' => $thesisId] as $code => $templateId) {
    $link->execute([$templateId, $code]);
}

$pdo->commit();

printf("Oral presentation:  %d criteria added (max %s)\n", $added1,
    $pdo->query("SELECT COALESCE(SUM(max_score),0) FROM rubric_criteria WHERE template_id = '$oralId'")->fetchColumn());
printf("Thesis marking:     %d criteria added (max %s)\n", $added2,
    $pdo->query("SELECT COALESCE(SUM(max_score),0) FROM rubric_criteria WHERE template_id = '$thesisId'")->fetchColumn());
printf("Interpretation bands added: %d\n", $addedBands);

echo "\nStage links:\n";
foreach ($pdo->query(
    "SELECT s.name AS stage, t.name AS template
     FROM exam_stages s LEFT JOIN rubric_templates t ON t.template_id = s.rubric_template_id
     ORDER BY s.display_order"
)->fetchAll() as $row) {
    printf("  %-22s %s\n", $row['stage'], $row['template'] ?? '(none)');
}
