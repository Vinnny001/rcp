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



    ]);
};