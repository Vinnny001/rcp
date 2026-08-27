<?php

declare(strict_types=1);

use DI\ContainerBuilder;
use Psr\Log\LoggerInterface;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Processor\UidProcessor;
use Slim\Views\Twig;
use Psr\Container\ContainerInterface;

use App\Models\Lecturer;
use App\Models\Proposal;
use App\Models\Document;
use App\Models\Payment;
use App\Models\Meeting;
use App\Controllers\StudentMeetingsController;

use App\Models\Examination;
use App\Models\Graduation;
use App\Controllers\StudentExamController;

use App\Controllers\LecturerOverviewController;
use App\Controllers\LecturerSupervisionController;
use App\Models\SupervisionRequest;
use App\Controllers\LecturerMeetingsController;
use App\Controllers\LecturerDocumentsController;



use App\Models\StudentEnrollment;
use App\Models\StudentLeave;
use App\Models\ProgramSchedule;
use App\Controllers\StudentEnrollmentController;


use App\Models\ThesisRegistration;
use App\Models\ThesisFeeRate;
use App\Models\ThesisPayment;
use App\Controllers\StudentThesisController;


use App\Models\Department;
use App\Models\Program;

use App\Models\AdminUser;


use App\Middleware\StudentContextMiddleware;
use App\Controllers\StudentProfileController;

use App\Controllers\StudentDocumentsController;
use App\Controllers\LecturerThesesController;
use App\Controllers\AdminController;
use App\Controllers\StudentOutcomesController;

// ExaminationScore is a model, not a controller — the old
// App\Controllers import here pointed at a class that doesn't exist.
use App\Models\ExaminationScore;
use App\Models\DocumentReviewScore;
use App\Models\Role;
use App\Models\ThesisSchedule;
use App\Models\ExamSchedule;


return function (ContainerBuilder $containerBuilder) {
    $containerBuilder->addDefinitions([
        LoggerInterface::class => function (ContainerInterface $c) {
            $settings = $c->get('settings');
            $loggerSettings = $settings['logger'];
            $logger = new Logger($loggerSettings['name']);
            $processor = new UidProcessor();
            $logger->pushProcessor($processor);
            $handler = new StreamHandler($loggerSettings['path'], $loggerSettings['level']);
            $logger->pushHandler($handler);
            return $logger;
        },

        // Twig for Views
        Twig::class => function () {
            return Twig::create(__DIR__ . '/../src/Views', ['cache' => false]);
        },

        // PDO Database connection
        PDO::class => function () {
            $host = $_ENV['DB_HOST'] ?? '';
            $db   = $_ENV['DB_NAME'] ?? '';
            $user = $_ENV['DB_USER'] ?? '';
            $pass = $_ENV['DB_PASS'] ?? '';

            $dsn = "mysql:host=$host;dbname=$db;charset=utf8mb4";

            return new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        },


        Proposal::class => function (ContainerInterface $c) {
    return new Proposal($c->get(PDO::class));
},

Lecturer::class => function (ContainerInterface $c) {
    return new Lecturer($c->get(PDO::class));
},


Document::class => function (ContainerInterface $c) {
    return new Document($c->get(PDO::class));
},

Payment::class => function (ContainerInterface $c) {
    return new Payment($c->get(PDO::class));
},

Meeting::class => function (ContainerInterface $c) {
    return new Meeting($c->get(PDO::class));
},

StudentMeetingsController::class => function (ContainerInterface $c) {
    return new StudentMeetingsController($c->get(PDO::class), $c->get(Twig::class));
},


Examination::class => function (ContainerInterface $c) {
    return new Examination($c->get(PDO::class));
},

Graduation::class => function (ContainerInterface $c) {
    return new Graduation($c->get(PDO::class));
},

StudentExamController::class => function (ContainerInterface $c) {
    return new StudentExamController($c->get(PDO::class), $c->get(Twig::class));
},

// Lecturer Overview Controller
LecturerOverviewController::class => function (ContainerInterface $c) {
    return new LecturerOverviewController($c->get(PDO::class), $c->get(Twig::class));
},


SupervisionRequest::class => function (ContainerInterface $c) {
    return new SupervisionRequest($c->get(PDO::class));
},

LecturerSupervisionController::class => function (ContainerInterface $c) {
    return new LecturerSupervisionController($c->get(PDO::class), $c->get(Twig::class));
},


LecturerMeetingsController::class => function (ContainerInterface $c) {
    return new LecturerMeetingsController($c->get(PDO::class), $c->get(Twig::class));
},





StudentEnrollment::class => function (ContainerInterface $c) {
    return new StudentEnrollment($c->get(PDO::class));
},

StudentLeave::class => function (ContainerInterface $c) {
    return new StudentLeave($c->get(PDO::class));
},

ProgramSchedule::class => function (ContainerInterface $c) {
    return new ProgramSchedule($c->get(PDO::class));
},

StudentEnrollmentController::class => function (ContainerInterface $c) {
    return new StudentEnrollmentController($c->get(PDO::class), $c->get(Twig::class));
},



ThesisRegistration::class => function (ContainerInterface $c) {
    return new ThesisRegistration($c->get(PDO::class));
},

ThesisFeeRate::class => function (ContainerInterface $c) {
    return new ThesisFeeRate($c->get(PDO::class));
},

ThesisPayment::class => function (ContainerInterface $c) {
    return new ThesisPayment($c->get(PDO::class));
},

StudentThesisController::class => function (ContainerInterface $c) {
    return new StudentThesisController($c->get(PDO::class), $c->get(Twig::class));
},



Department::class => function (ContainerInterface $c) {
    return new Department($c->get(PDO::class));
},

Program::class => function (ContainerInterface $c) {
    return new Program($c->get(PDO::class));
},



AdminUser::class => function (ContainerInterface $c) {
    return new AdminUser($c->get(PDO::class));
},

LecturerDocumentsController::class => function (ContainerInterface $c) {
    return new LecturerDocumentsController($c->get(PDO::class), $c->get(Twig::class));
},


StudentContextMiddleware::class => function (ContainerInterface $c) {
    return new StudentContextMiddleware($c->get(PDO::class), $c->get(Twig::class));
},


StudentProfileController::class => function (ContainerInterface $c) {
    return new StudentProfileController($c->get(PDO::class), $c->get(Twig::class));
},


StudentDocumentsController::class => function (ContainerInterface $c) {
    return new StudentDocumentsController($c->get(PDO::class), $c->get(Twig::class));
},


ExaminationScore::class => function (ContainerInterface $c) {
    return new ExaminationScore($c->get(PDO::class));
},

DocumentReviewScore::class => function (ContainerInterface $c) {
    return new DocumentReviewScore($c->get(PDO::class));
},

Role::class => function (ContainerInterface $c) {
    return new Role($c->get(PDO::class));
},

ThesisSchedule::class => function (ContainerInterface $c) {
    return new ThesisSchedule($c->get(PDO::class));
},

ExamSchedule::class => function (ContainerInterface $c) {
    return new ExamSchedule($c->get(PDO::class));
},

AdminController::class => function (ContainerInterface $c) {
    return new AdminController($c->get(PDO::class), $c->get(Twig::class));
},

StudentOutcomesController::class => function (ContainerInterface $c) {
    return new StudentOutcomesController($c->get(PDO::class), $c->get(Twig::class));
},


LecturerThesesController::class => function (ContainerInterface $c) {
    return new LecturerThesesController($c->get(PDO::class), $c->get(Twig::class));
},

\App\Controllers\LecturerProfileController::class => function (ContainerInterface $c) {
    return new \App\Controllers\LecturerProfileController($c->get(PDO::class), $c->get(Twig::class));
},

\App\Controllers\LecturerNotificationsController::class => function (ContainerInterface $c) {
    return new \App\Controllers\LecturerNotificationsController($c->get(PDO::class), $c->get(Twig::class));
},

\App\Controllers\StudentNotificationsController::class => function (ContainerInterface $c) {
    return new \App\Controllers\StudentNotificationsController($c->get(PDO::class), $c->get(Twig::class));
},

\App\Middleware\NotificationBadgeMiddleware::class => function (ContainerInterface $c) {
    return new \App\Middleware\NotificationBadgeMiddleware($c->get(PDO::class), $c->get(Twig::class));
},

    ]);
};