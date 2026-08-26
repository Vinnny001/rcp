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
    // and at least one document to offer as a resource.
    $assignment = $pdo->prepare(
        "SELECT sa.proposal_id, s.user_id AS student_user_id
         FROM supervision_assignments sa
         JOIN students s ON s.student_id = sa.student_id
         WHERE sa.supervisor_id = :lecturer_id AND sa.is_active = 1
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
} catch (\Throwable $e) {
    $fail++;
    echo "\n  ERROR  " . $e->getMessage() . "\n         " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    // Real cleanup — not a transaction rollback, since the writes above
    // did not happen on this script's own connection.
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
