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

use App\Controllers\LecturerOverviewController;
use App\Controllers\LecturerSupervisionController;
use App\Controllers\LecturerMeetingsController;

use App\Controllers\StudentEnrollmentController;
use App\Controllers\StudentThesisController;

use App\Controllers\AdminController;


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
    $app->post('/student/requirements/upload', [StudentRequirementsController::class, 'uploadDocument']);
    

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



    // Student enrollment routes
    $app->get('/student/enroll', [StudentEnrollmentController::class, 'show']);
    $app->post('/student/enroll', [StudentEnrollmentController::class, 'enroll']);
    $app->post('/student/leave/request', [StudentEnrollmentController::class, 'requestLeave']);


    $app->get('/student/thesis', [StudentThesisController::class, 'show']);
    $app->post('/student/thesis/register', [StudentThesisController::class, 'register']);


    $app->post('/student/thesis/pay', [StudentThesisController::class, 'pay']);



    $app->get('/admin/dashboard', [AdminController::class, 'dashboard']);


    $app->get('/admin/users', [AdminController::class, 'users']);
$app->post('/admin/users/toggle', [AdminController::class, 'toggleUser']);


};