<?php

declare(strict_types=1);

use App\Application\Actions\User\ListUsersAction;
use App\Application\Actions\User\ViewUserAction;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Interfaces\RouteCollectorProxyInterface as Group;
use App\Controllers\AuthController;
use App\Controllers\StudentController;
use App\Controllers\StudentProposalController;
use App\Controllers\DashboardController;

use App\Controllers\StudentRequirementsController;
use App\Controllers\StudentMeetingsController;
use App\Controllers\StudentExamController;
use App\Controllers\StudentEnrollmentController;
use App\Controllers\StudentThesisController;

use App\Controllers\LecturerOverviewController;
use App\Controllers\LecturerSupervisionController;
use App\Controllers\LecturerMeetingsController;
use App\Controllers\LecturerDocumentsController;



use App\Controllers\AdminController;
use App\Controllers\StudentProfileController;

use App\Controllers\StudentDocumentsController;
use App\Controllers\LecturerThesesController;
use App\Controllers\StudentOutcomesController;
use App\Controllers\LecturerProfileController;
use App\Controllers\LecturerNotificationsController;
use App\Controllers\StudentNotificationsController;


return function (App $app) {
    $app->options('/{routes:.*}', function (Request $request, Response $response) {
        return $response;
    });

    $app->get('/', function (Request $request, Response $response) {
        $response->getBody()->write('Hello world!');
        return $response;
    });

    $app->group('/users', function (Group $group) {
        $group->get('', ListUsersAction::class);
        $group->get('/{id}', ViewUserAction::class);
    });

    $app->get('/login', [AuthController::class, 'showLoginForm']);
    $app->post('/login', [AuthController::class, 'login']);
    $app->get('/register', [AuthController::class, 'showRegisterForm']);
    $app->post('/register', [AuthController::class, 'register']);

    $app->get('/dashboard', [DashboardController::class, 'show']);

    $app->get('/student/dashboard', [StudentController::class, 'dashboard']);

    $app->post('/logout', [AuthController::class, 'logout']);

    // Student proposal routes
    $app->get('/student/proposal', [StudentProposalController::class, 'show']);
    $app->post('/student/proposal', [StudentProposalController::class, 'store']);

    // Student requirements routes
    $app->get('/student/requirements', [StudentRequirementsController::class, 'show']);
    $app->post('/student/requirements/upload', [StudentRequirementsController::class, 'upload']);    

    // Student meetings routes
    $app->get('/student/meetings', [StudentMeetingsController::class, 'show']);

    // Student exam & graduation route
    $app->get('/student/exam', [StudentExamController::class, 'show']);


// LECTURER ROUTES
    // Lecturer overview route
    $app->get('/lecturer/dashboard', [LecturerOverviewController::class, 'show']);

    // Lecturer supervision routes
    $app->get('/lecturer/supervision', [LecturerSupervisionController::class, 'show']);
    $app->post('/lecturer/supervision/accept', [LecturerSupervisionController::class, 'accept']);
    $app->post('/lecturer/supervision/decline', [LecturerSupervisionController::class, 'decline']);
    $app->post('/lecturer/supervision/documents/validate', [LecturerSupervisionController::class, 'validateDocument']);


    $app->get('/lecturer/meetings', [LecturerMeetingsController::class, 'show']);
    $app->post('/lecturer/meetings/schedule', [LecturerMeetingsController::class, 'schedule']);
    $app->post('/lecturer/meetings/grade', [LecturerMeetingsController::class, 'grade']);
    // Lecturer documents route
    $app->get('/lecturer/documents', [LecturerDocumentsController::class, 'show']);
    $app->post('/lecturer/documents/validate', [LecturerDocumentsController::class, 'validateDocument']);
    $app->get('/lecturer/my-documents', [LecturerDocumentsController::class, 'myDocuments']);
    $app->post('/lecturer/my-documents/upload', [LecturerDocumentsController::class, 'uploadMyDocument']);



    // Student enrollment routes
    $app->get('/student/enroll', [StudentEnrollmentController::class, 'show']);
    $app->post('/student/enroll', [StudentEnrollmentController::class, 'enroll']);
    $app->post('/student/leave/request', [StudentEnrollmentController::class, 'requestLeave']);


    $app->get('/student/thesis', [StudentThesisController::class, 'show']);


    $app->post('/student/proposal/document/remove', [StudentProposalController::class, 'removeDocument']);
   
    $app->get('/student/thesis/register', [StudentThesisController::class, 'showRegisterPicker']);
    $app->post('/student/thesis/register', [StudentThesisController::class, 'register']);

    $app->post('/student/thesis/pay', [StudentThesisController::class, 'pay']);



    $app->get('/admin/dashboard', [AdminController::class, 'dashboard']);


    $app->get('/admin/users', [AdminController::class, 'users']);
$app->post('/admin/users/toggle', [AdminController::class, 'toggleUser']);
$app->post('/admin/users/toggle-examiner', [AdminController::class, 'toggleExaminer']);
$app->post('/admin/users/max-supervision-load', [AdminController::class, 'updateMaxSupervisionLoad']);
$app->post('/admin/users/affiliation', [AdminController::class, 'updateAffiliation']);


$app->get('/admin/departments', [AdminController::class, 'departments']);
$app->post('/admin/departments/create', [AdminController::class, 'createDepartment']);
$app->post('/admin/departments/update', [AdminController::class, 'updateDepartment']);
$app->post('/admin/departments/delete', [AdminController::class, 'deleteDepartment']);

$app->get('/admin/programs', [AdminController::class, 'programs']);
$app->post('/admin/programs/create', [AdminController::class, 'createProgram']);
$app->post('/admin/programs/update', [AdminController::class, 'updateProgram']);
$app->post('/admin/programs/delete', [AdminController::class, 'deleteProgram']);

$app->add($app->getContainer()->get(\App\Middleware\StudentContextMiddleware::class));
$app->add($app->getContainer()->get(\App\Middleware\NotificationBadgeMiddleware::class));



$app->get('/student/profile/complete', [StudentProfileController::class, 'show']);
$app->post('/student/profile/complete', [StudentProfileController::class, 'update']);


$app->post('/student/requirements/submit-draft', [StudentRequirementsController::class, 'submitDraft']);



$app->get('/student/documents', [StudentDocumentsController::class, 'show']);

$app->post('/lecturer/meetings/schedule-for-exam', [LecturerMeetingsController::class, 'scheduleForExam']);


$app->get('/lecturer/meetings/{id}/review', [LecturerMeetingsController::class, 'review']);
$app->get('/lecturer/meetings/{id}/proposal', [LecturerMeetingsController::class, 'proposalOverview']);
$app->post('/lecturer/meetings/{id}/review', [LecturerMeetingsController::class, 'submitReview']);
$app->get('/lecturer/meetings/{id}/edit', [LecturerMeetingsController::class, 'editForm']);
$app->post('/lecturer/meetings/{id}/edit', [LecturerMeetingsController::class, 'updateMeeting']);
$app->post('/lecturer/meetings/{id}/status', [LecturerMeetingsController::class, 'changeStatus']);

// Student results — banded exam and document review outcomes
$app->get('/student/outcomes', [StudentOutcomesController::class, 'show']);

// Admin: user roles
$app->post('/admin/users/role', [AdminController::class, 'updateUserRole']);

// Admin: thesis schedules
$app->get('/admin/thesis-schedules', [AdminController::class, 'thesisSchedules']);
$app->get('/admin/thesis-schedules/{id}/students', [AdminController::class, 'thesisScheduleStudents']);
$app->post('/admin/thesis-schedules/create', [AdminController::class, 'createThesisSchedule']);
$app->post('/admin/thesis-schedules/update', [AdminController::class, 'updateThesisSchedule']);
$app->post('/admin/thesis-schedules/delete', [AdminController::class, 'deleteThesisSchedule']);

// Admin: exam schedules and their required documents
$app->get('/admin/exam-schedules', [AdminController::class, 'examSchedules']);
$app->post('/admin/exam-schedules/create', [AdminController::class, 'createExamSchedule']);
$app->post('/admin/exam-schedules/update', [AdminController::class, 'updateExamSchedule']);
$app->post('/admin/exam-schedules/delete', [AdminController::class, 'deleteExamSchedule']);
$app->post('/admin/exam-schedules/documents/add', [AdminController::class, 'addExamScheduleDocument']);
$app->post('/admin/exam-schedules/documents/remove', [AdminController::class, 'removeExamScheduleDocument']);

// Admin: fees & rates
$app->get('/admin/fee-rates', [AdminController::class, 'feeRates']);
$app->post('/admin/fee-rates/registration/create', [AdminController::class, 'createRegistrationRate']);
$app->post('/admin/fee-rates/registration/update', [AdminController::class, 'updateRegistrationRate']);
$app->post('/admin/fee-rates/registration/delete', [AdminController::class, 'deleteRegistrationRate']);
$app->post('/admin/fee-rates/review/create', [AdminController::class, 'createReviewFeeRate']);
$app->post('/admin/fee-rates/review/update', [AdminController::class, 'updateReviewFeeRate']);
$app->post('/admin/fee-rates/review/delete', [AdminController::class, 'deleteReviewFeeRate']);
$app->post('/admin/fee-rates/documents/create', [AdminController::class, 'createDocumentReviewRate']);
$app->post('/admin/fee-rates/documents/update', [AdminController::class, 'updateDocumentReviewRate']);
$app->post('/admin/fee-rates/documents/delete', [AdminController::class, 'deleteDocumentReviewRate']);

// Admin: audit log
$app->get('/admin/audit', [AdminController::class, 'auditLog']);



$app->get('/lecturer/theses', [LecturerThesesController::class, 'show']);
$app->get('/lecturer/theses/{id}', [LecturerThesesController::class, 'detail']);

// Lecturer & student profile pages
$app->get('/lecturer/profile', [LecturerProfileController::class, 'show']);
$app->post('/lecturer/profile/availability', [LecturerProfileController::class, 'toggleAvailability']);
$app->get('/student/profile', [StudentProfileController::class, 'account']);

// Admin: exam review attachments
$app->get('/admin/exam-review-attachments', [AdminController::class, 'examReviewAttachments']);

// Notifications
$app->get('/admin/notifications', [AdminController::class, 'notifications']);
$app->post('/admin/notifications/send', [AdminController::class, 'sendNotification']);

$app->get('/lecturer/notifications', [LecturerNotificationsController::class, 'show']);
$app->post('/lecturer/notifications/send', [LecturerNotificationsController::class, 'send']);
$app->post('/lecturer/notifications/{id}/read', [LecturerNotificationsController::class, 'markRead']);

$app->get('/student/notifications', [StudentNotificationsController::class, 'show']);
$app->post('/student/notifications/{id}/read', [StudentNotificationsController::class, 'markRead']);

};