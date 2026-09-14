<?php

/**
 * Moves requests caught mid-way under the old one-at-a-time rule onto
 * the new one.
 *
 * Before 2026_09_14_supervisor_request_rounds.sql, approving a request
 * contacted the first lecturer immediately and the rest waited their
 * turn. Such a request is 'approved' with some lecturers already asked
 * and the others never asked at all. Under the new rule everyone is
 * asked together, so this sends the lecturers who were still waiting
 * their turn, opens the response window from today, and leaves anyone
 * already asked holding the request they have.
 *
 * A request approved under the new rule has nobody asked yet — it is
 * waiting for the coordinator to send it — and is left alone. That is
 * also what makes re-running this safe: once converted, a request is
 * 'requests_sent' and no longer matches.
 *
 * Run: php database/migrations/2026_09_14_send_in_flight_requests.php
 */

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use App\Models\SupervisorShortlist;

$env = parse_ini_file(__DIR__ . '/../../.env');
$pdo = new PDO(
    "mysql:host={$env['DB_HOST']};dbname={$env['DB_NAME']};charset=utf8mb4",
    $env['DB_USER'],
    $env['DB_PASS'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$inFlight = $pdo->query(
    "SELECT s.shortlist_id, s.created_by, m.minutes_approved_by,
            CONCAT(u.first_name, ' ', u.last_name) AS student_name
     FROM supervisor_shortlists s
     JOIN students st ON st.student_id = s.student_id
     JOIN users u ON u.user_id = st.user_id
     LEFT JOIN shortlist_meetings m ON m.shortlist_id = s.shortlist_id
     WHERE s.status = 'approved'
       AND EXISTS (
           SELECT 1 FROM supervisor_shortlist_choices c
           WHERE c.shortlist_id = s.shortlist_id AND c.request_status <> 'not_sent'
       )"
)->fetchAll();

if ($inFlight === []) {
    echo "Nothing to convert.\n";
    exit(0);
}

$model = new SupervisorShortlist($pdo);

foreach ($inFlight as $request) {
    // Credited to the coordinator who approved the minutes, the nearest
    // thing to whoever would have pressed Send.
    $sentBy = $request['minutes_approved_by'] ?? $request['created_by'];
    $result = $model->sendRequests($request['shortlist_id'], $sentBy);

    printf(
        "%s: %d more lecturer(s) asked, %d at full load; outcome %s\n",
        $request['student_name'],
        $result['sent'],
        $result['unavailable'],
        $result['outcome'] ?? 'still open'
    );
}
