<?php

/**
 * Drives the real Slim app over simulated HTTP requests — POSTs exactly
 * what a browser form would send — for flows that are hard to trust
 * from unit-level model calls alone: scheduling a meeting with
 * resources, and uploading a document.
 *
 * IMPORTANT: unlike manual_meeting_flow.php, this does NOT run inside a
 * rolled-back transaction. Each buildApp() call resolves its own PDO
 * connection via the DI container (see app/dependencies.php), so a
 * request handled by the app writes on a *different* connection than
 * this script's own $pdo — a transaction opened on $pdo would never
 * cover those writes, and rolling it back would silently leave real
 * rows behind. Everything created here is therefore tracked and
 * deleted for real in the finally block.
 *
 * Run: php tests/manual_http_flow.php
 */

declare(strict_types=1);

use DI\ContainerBuilder;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\UploadedFile;

require __DIR__ . '/../vendor/autoload.php';

Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->load();

function buildApp(): Slim\App
{
    $containerBuilder = new ContainerBuilder();
    (require __DIR__ . '/../app/settings.php')($containerBuilder);
    (require __DIR__ . '/../app/dependencies.php')($containerBuilder);
    (require __DIR__ . '/../app/repositories.php')($containerBuilder);
    $container = $containerBuilder->build();

    AppFactory::setContainer($container);
    $app = AppFactory::create();
    (require __DIR__ . '/../app/middleware.php')($app);
    (require __DIR__ . '/../app/routes.php')($app);
    $app->addRoutingMiddleware();
    $app->addBodyParsingMiddleware();
    $app->addErrorMiddleware(true, false, false);

    return $app;
}

$pdo = buildApp()->getContainer()->get(PDO::class);

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . ($detail !== '' ? ' — ' . $detail : '') . "\n";
}

function post(string $path, array $formFields, array $uploadedFiles, array $session): array
{
    $app = buildApp();
    $_SESSION = $session;

    $request = (new ServerRequestFactory())->createServerRequest('POST', $path)
        ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
        ->withParsedBody($formFields)
        ->withUploadedFiles($uploadedFiles);

    $response = $app->handle($request);
    return ['status' => $response->getStatusCode(), 'location' => $response->getHeaderLine('Location')];
}

function get(string $path, array $session): string
{
    $app = buildApp();
    $_SESSION = $session;
    $request = (new ServerRequestFactory())->createServerRequest('GET', $path);
    return (string) $app->handle($request)->getBody();
}

// A location string unique to this run — the cleanup at the end matches
// on it, so a crashed prior run's leftovers are also swept up rather
// than accumulating across runs.
$marker = 'HTTP-TEST-' . bin2hex(random_bytes(6));
$createdMeetingId = null;
$createdDocumentId = null;
$flippedDocumentId = null;
$flippedDocumentStatus = null;
$uploadedFixturePath = null;

try {
    $lecturer = $pdo->query(
        "SELECT u.user_id, u.first_name, u.last_name, l.lecturer_id
         FROM users u
         JOIN lecturers l ON l.user_id = u.user_id
         WHERE EXISTS (
             SELECT 1 FROM supervision_assignments sa
             WHERE sa.supervisor_id = l.lecturer_id AND sa.is_active = 1
         )
         LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);

    $session = [
        'user_id'    => $lecturer['user_id'],
        'role'       => 'lecturer',
        'first_name' => $lecturer['first_name'],
        'last_name'  => $lecturer['last_name'],
        'csrf_token' => str_repeat('a', 64),
    ];

    // A student this lecturer actually supervises, with a proposal_id
    // and at least one document to offer as a resource. A supervisee
    // who also holds a lecturer account is preferred, since that is the
    // account the attendee guard below actually has to catch.
    $assignment = $pdo->prepare(
        "SELECT sa.proposal_id, sa.student_id, s.user_id AS student_user_id,
                (l.lecturer_id IS NOT NULL) AS subject_is_lecturer
         FROM supervision_assignments sa
         JOIN students s ON s.student_id = sa.student_id
         LEFT JOIN lecturers l ON l.user_id = s.user_id
         WHERE sa.supervisor_id = :lecturer_id AND sa.is_active = 1
         ORDER BY subject_is_lecturer DESC
         LIMIT 1"
    );
    $assignment->execute(['lecturer_id' => $lecturer['lecturer_id']]);
    $assignment = $assignment->fetch(PDO::FETCH_ASSOC);

    $studentDocId = $pdo->prepare("SELECT document_id FROM documents WHERE user_id = :uid LIMIT 1");
    $studentDocId->execute(['uid' => $assignment['student_user_id']]);
    $studentDocId = $studentDocId->fetchColumn();

    echo "\n--- schedule a meeting over real HTTP, with resources ---\n";

    $result = post('/lecturer/meetings/schedule', [
        'csrf_token'         => $session['csrf_token'],
        'proposal_id'        => $assignment['proposal_id'],
        'meeting_type'       => 'supervisory',
        'date'               => date('Y-m-d', strtotime('+6 days')),
        'time'               => '10:00',
        'mode'               => 'physical',
        'location'           => $marker,
        'resource_documents' => [$studentDocId],
        'resource_links'     => ['https://example.org/agenda'],
        'resource_link_labels' => ['Agenda'],
    ], [], $session);

    check('schedule POST redirects (success)', $result['status'] === 302 && $result['location'] === '/lecturer/meetings');

    // The invite dropdown hides a supervisee who also holds a lecturer
    // account, but hiding is not enforcing. Post their id straight into
    // the attendee list and the server has to refuse it — otherwise a
    // student could be invited to examine their own work.
    $conflicted = $pdo->prepare(
        "SELECT s.user_id FROM students s
         JOIN lecturers l ON l.user_id = s.user_id
         WHERE s.student_id = :student_id LIMIT 1"
    );
    $conflicted->execute(['student_id' => $assignment['student_id']]);
    $subjectIsAlsoLecturer = $conflicted->fetchColumn();

    $smuggleMarker = 'SMUGGLE-' . bin2hex(random_bytes(4));
    $smuggled = post('/lecturer/meetings/schedule', [
        'csrf_token'         => $session['csrf_token'],
        'proposal_id'        => $assignment['proposal_id'],
        'meeting_type'       => 'supervisory',
        'date'               => date('Y-m-d', strtotime('+7 days')),
        'time'               => '11:00',
        'mode'               => 'physical',
        'location'           => $smuggleMarker,
        'attendee_lecturers' => [$assignment['student_user_id']],
        'attendee_roles'     => ['examiner'],
    ], [], $session);

    $smuggledMeeting = $pdo->prepare("SELECT COUNT(*) FROM meetings WHERE location = :marker");
    $smuggledMeeting->execute(['marker' => $smuggleMarker]);

    check(
        'the meeting\'s own student cannot be posted in as an examiner',
        (int) $smuggledMeeting->fetchColumn() === 0,
        $subjectIsAlsoLecturer ? 'subject also holds a lecturer account' : 'subject is a student only'
    );

    $createdMeetingId = $pdo->prepare("SELECT meeting_id FROM meetings WHERE location = :marker LIMIT 1");
    $createdMeetingId->execute(['marker' => $marker]);
    $createdMeetingId = $createdMeetingId->fetchColumn() ?: null;
    check('the meeting was actually created', $createdMeetingId !== null);

    $actualResources = $pdo->prepare("SELECT resource_type, document_id, url, label FROM meeting_resources WHERE meeting_id = :id");
    $actualResources->execute(['id' => $createdMeetingId]);
    $actualResources = $actualResources->fetchAll(PDO::FETCH_ASSOC);
    check(
        'two resources were written via the real controller',
        count($actualResources) === 2,
        count($actualResources) . ' found: ' . json_encode($actualResources)
    );

    $page = get('/lecturer/meetings', $session);
    check('the new meeting page shows the link label', str_contains($page, 'Agenda'));

    echo "\n--- upload a document over real HTTP (multipart) ---\n";

    $fixturePath = sys_get_temp_dir() . '/rcp_test_fixture.pdf';
    file_put_contents($fixturePath, '%PDF-1.4 test fixture');
    $uploadedFixturePath = $fixturePath;

    $docTypeId = $pdo->query("SELECT doc_type_id FROM document_types ORDER BY doc_type_name LIMIT 1")->fetchColumn();
    $fixtureFileName = $marker . '.pdf';

    // sapi=false: this is a plain temp file, not a real PHP-managed
    // upload, so moveTo() must use rename() rather than
    // move_uploaded_file() — the latter would refuse a non-SAPI-uploaded path.
    $uploadedFile = new UploadedFile($fixturePath, $fixtureFileName, 'application/pdf', filesize($fixturePath), UPLOAD_ERR_OK, false);

    $result = post('/lecturer/my-documents/upload', [
        'csrf_token'        => $session['csrf_token'],
        'document_type_id'  => $docTypeId,
    ], ['document_file' => $uploadedFile], $session);

    check('upload POST redirects (success)', $result['status'] === 302 && $result['location'] === '/lecturer/my-documents');

    $uploaded = $pdo->prepare(
        "SELECT document_id, file_path, document_status FROM documents
         WHERE user_id = :uid AND file_name = :file_name ORDER BY uploaded_at DESC LIMIT 1"
    );
    $uploaded->execute(['uid' => $lecturer['user_id'], 'file_name' => $fixtureFileName]);
    $uploaded = $uploaded->fetch(PDO::FETCH_ASSOC);
    $createdDocumentId = $uploaded['document_id'] ?? null;

    check('a document row was created', (bool) $uploaded);
    check("status is 'final' — not part of any review pipeline", ($uploaded['document_status'] ?? null) === 'final');

    $storedFile = $uploaded ? (__DIR__ . '/../public/' . $uploaded['file_path']) : null;
    check('the file was actually written to disk', $storedFile && is_file($storedFile));

    $myDocsPage = get('/lecturer/my-documents', $session);
    check('the uploaded document appears on My Documents', str_contains($myDocsPage, $fixtureFileName));

    // ---- the proposal and request form's server-side refusals ----
    //
    // Posted straight at the server rather than through the page, because
    // the point is that the server refuses them even when a form did not.
    // Every one is refused before anything is written, apart from the one
    // document status flipped for the last check, which is put back.
    echo "\n--- proposal and request refusals over real HTTP ---\n";

    $csrf = str_repeat('a', 64);
    $asStudent = fn (array $row): array => [
        'user_id' => $row['user_id'], 'role' => 'student',
        'first_name' => 'S', 'last_name' => 'T', 'csrf_token' => $csrf,
    ];
    $shortlistBefore = (int) $pdo->query("SELECT COUNT(*) FROM supervisor_shortlists")->fetchColumn();
    $someLecturer = $pdo->query("SELECT lecturer_id FROM lecturers LIMIT 1")->fetchColumn();

    // A request already with the coordinator, department or lecturers.
    $lockedStudent = $pdo->query(
        "SELECT st.user_id, tp.title FROM supervisor_shortlists sl
         JOIN students st ON st.student_id = sl.student_id
         JOIN thesis_proposals tp ON tp.proposal_id = sl.proposal_id
         WHERE sl.status IN ('pending_coordinator', 'meeting_scheduled', 'approved', 'requests_sent')
         LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);

    if ($lockedStudent) {
        $result = post('/student/proposal', [
            'csrf_token' => $csrf, 'action' => 'submit',
            'title' => 'Changed after sending', 'synopsis' => str_repeat('A changed synopsis. ', 5),
            'rank' => [$someLecturer => '1'], 'preferred_main' => '',
        ], [], $asStudent($lockedStudent));

        check('a student cannot resend or change a request that has been sent',
            $result['location'] === '/student/proposal'
            && str_contains((string) ($_SESSION['flash_error'] ?? ''), 'cannot be changed'),
            (string) ($_SESSION['flash_error'] ?? 'no error set'));
        check('and the proposal they sent is untouched',
            $pdo->query("SELECT COUNT(*) FROM thesis_proposals WHERE title = 'Changed after sending'")->fetchColumn() == 0);
    } else {
        echo "  SKIP  no request in flight to try to change\n";
    }

    // A student who has not sent a request yet, so the list is theirs.
    $openStudent = $pdo->query(
        "SELECT st.user_id FROM students st
         JOIN thesis_proposals tp ON tp.student_id = st.student_id AND tp.status NOT IN ('draft', 'rejected')
         WHERE NOT EXISTS (SELECT 1 FROM supervisor_shortlists sl WHERE sl.student_id = st.student_id)
         LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);

    if ($openStudent) {
        post('/student/proposal', [
            'csrf_token' => $csrf, 'action' => 'submit', 'rank' => [], 'preferred_main' => '',
        ], [], $asStudent($openStudent));
        check('a request cannot be sent with no supervisors on it',
            str_contains((string) ($_SESSION['flash_error'] ?? ''), 'at least one supervisor'),
            (string) ($_SESSION['flash_error'] ?? 'no error set'));

        post('/student/proposal', [
            'csrf_token' => $csrf, 'action' => 'draft',
            'rank' => [$someLecturer => ''], 'preferred_main' => $someLecturer,
        ], [], $asStudent($openStudent));
        check('a preferred main who is not on the list is refused, not silently dropped',
            str_contains((string) ($_SESSION['flash_error'] ?? ''), 'not on your list'),
            (string) ($_SESSION['flash_error'] ?? 'no error set'));

        $old = post('/student/supervisors', [
            'csrf_token' => $csrf, 'rank' => [$someLecturer => '1'], 'preferred_main' => '',
        ], [], $asStudent($openStudent));
        check('the retired supervisors page sends students to the combined one',
            $old['location'] === '/student/proposal');
    } else {
        echo "  SKIP  no student without a request to post as\n";
    }

    check('none of those refusals wrote a request',
        (int) $pdo->query("SELECT COUNT(*) FROM supervisor_shortlists")->fetchColumn() === $shortlistBefore);

    // A file still marked draft on a proposal that has been sent must
    // not be removable — the proposal is what locks it, not the file.
    $sentDoc = $pdo->query(
        "SELECT d.document_id, d.document_status, st.user_id
         FROM exam_documents ed
         JOIN documents d ON d.document_id = ed.document_id
         JOIN thesis_proposals tp ON tp.proposal_id = ed.proposal_id AND tp.status NOT IN ('draft', 'rejected')
         JOIN students st ON st.student_id = tp.student_id
         LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);

    if ($sentDoc) {
        $flippedDocumentId = $sentDoc['document_id'];
        $flippedDocumentStatus = $sentDoc['document_status'];
        $pdo->prepare("UPDATE documents SET document_status = 'draft' WHERE document_id = ?")->execute([$flippedDocumentId]);

        post('/student/proposal/document/remove', [
            'csrf_token' => $csrf, 'document_id' => $flippedDocumentId,
        ], [], $asStudent($sentDoc));

        check('a draft-status file cannot be removed from a proposal that was sent',
            (int) $pdo->query("SELECT COUNT(*) FROM documents WHERE document_id = " . $pdo->quote($flippedDocumentId))->fetchColumn() === 1,
            (string) ($_SESSION['flash_error'] ?? ''));

        $pdo->prepare("UPDATE documents SET document_status = ? WHERE document_id = ?")
            ->execute([$flippedDocumentStatus, $flippedDocumentId]);
        $flippedDocumentId = null;
    } else {
        echo "  SKIP  no document on a sent proposal to try to remove\n";
    }
} catch (\Throwable $e) {
    $fail++;
    echo "\n  ERROR  " . $e->getMessage() . "\n         " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    // Real cleanup — not a transaction rollback, since the writes above
    // did not happen on this script's own connection.
    if ($flippedDocumentId) {
        $pdo->prepare("UPDATE documents SET document_status = ? WHERE document_id = ?")
            ->execute([$flippedDocumentStatus, $flippedDocumentId]);
    }
    if ($createdMeetingId) {
        $pdo->prepare("DELETE FROM meeting_resources WHERE meeting_id = :id")->execute(['id' => $createdMeetingId]);
        $pdo->prepare("DELETE FROM meeting_attendees WHERE meeting_id = :id")->execute(['id' => $createdMeetingId]);
        $pdo->prepare("DELETE FROM meetings WHERE meeting_id = :id")->execute(['id' => $createdMeetingId]);
    }
    // Sweep any other meetings this run's marker might have touched
    // (belt and braces — there should only ever be the one above).
    $pdo->prepare("DELETE FROM meeting_resources WHERE meeting_id IN (SELECT meeting_id FROM meetings WHERE location = :marker)")->execute(['marker' => $marker]);
    $pdo->prepare("DELETE FROM meeting_attendees WHERE meeting_id IN (SELECT meeting_id FROM meetings WHERE location = :marker)")->execute(['marker' => $marker]);
    $pdo->prepare("DELETE FROM meetings WHERE location = :marker")->execute(['marker' => $marker]);

    if ($createdDocumentId) {
        $path = $pdo->prepare("SELECT file_path FROM documents WHERE document_id = :id");
        $path->execute(['id' => $createdDocumentId]);
        $filePath = $path->fetchColumn();
        if ($filePath && is_file(__DIR__ . '/../public/' . $filePath)) {
            unlink(__DIR__ . '/../public/' . $filePath);
        }
        $pdo->prepare("DELETE FROM documents WHERE document_id = :id")->execute(['id' => $createdDocumentId]);
    }

    if ($uploadedFixturePath && is_file($uploadedFixturePath)) {
        unlink($uploadedFixturePath);
    }

    echo "\n(cleaned up — real writes were made and then explicitly deleted)\n";
}

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
