<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Models\ThesisRegistration;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Views\Twig;
use PDO;

class StudentContextMiddleware
{
    private PDO $db;
    private Twig $twig;

    /**
     * Paths that require an active, registration-fee-paid thesis
     * registration. Any path starting with one of these prefixes is
     * blocked and redirected — not just hidden from nav — for a student
     * who hasn't registered or hasn't paid.
     *
     * /student/thesis and /student/thesis/register are deliberately
     * NOT in this list — a student needs to reach those specifically
     * to register or pay, so gating them would create a lockout loop.
     * Only sub-paths like /student/thesis/pay are gated, via the
     * trailing-slash prefix below.
     */
    private const REGISTRATION_GATED_PREFIXES = [
        '/student/proposal',
        '/student/thesis/',
        '/student/requirements',
        '/student/meetings',
        '/student/exam',
    ];

    public function __construct(PDO $db, Twig $twig)
    {
        $this->db = $db;
        $this->twig = $twig;
    }

    public function __invoke(Request $request, Handler $handler)
    {
        if (($_SESSION['role'] ?? null) === 'student' && !empty($_SESSION['user_id'])) {
            $stmt = $this->db->prepare(
                "SELECT student_id, student_number, student_email FROM students WHERE user_id = :user_id LIMIT 1"
            );
            $stmt->execute(['user_id' => $_SESSION['user_id']]);
            $student = $stmt->fetch();

            $profileComplete = $student && !empty($student['student_number']) && !empty($student['student_email']);

            $hasRegistration = false;
            $registrationPaid = false;

            if ($student && $profileComplete) {
                $regModel = new ThesisRegistration($this->db);
                $registration = $regModel->findActiveByStudentId($student['student_id']);
                $hasRegistration = (bool) $registration;

                if ($registration) {
                    $owed = $regModel->computeOwed($registration);
                    $registrationPaid = true;
                    foreach ($owed as $item) {
                        if ($item['fee_type'] === 'thesis_registration') {
                            $registrationPaid = false;
                            break;
                        }
                    }
                }
            }

            $this->twig->getEnvironment()->addGlobal('profile_complete', $profileComplete);
            $this->twig->getEnvironment()->addGlobal('has_thesis_registration', $hasRegistration);
            $this->twig->getEnvironment()->addGlobal('registration_paid', $registrationPaid);
            $this->twig->getEnvironment()->addGlobal('show_thesis_link', $profileComplete && !$hasRegistration);

            $path = $request->getUri()->getPath();

            // Gate 1: incomplete profile blocks everything except the
            // profile-completion page itself.
            if (!$profileComplete && $path !== '/student/profile/complete') {
                $_SESSION['flash_error'] = 'Please complete your student profile before continuing.';
                return $this->redirectTo('/student/profile/complete');
            }

            // Gate 2: registration-gated pages require an active,
            // fully-paid registration.
            if ($profileComplete && $this->isRegistrationGated($path)) {
                if (!$hasRegistration) {
                    $_SESSION['flash_error'] = 'You need to register for thesis before accessing that page.';
                    return $this->redirectTo('/student/thesis/register');
                }
                if (!$registrationPaid) {
                    $_SESSION['flash_error'] = 'Please pay your thesis registration fee before accessing that page.';
                    return $this->redirectTo('/student/thesis');
                }
            }
        }

        return $handler->handle($request);
    }

    private function isRegistrationGated(string $path): bool
    {
        foreach (self::REGISTRATION_GATED_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }
        return false;
    }

    private function redirectTo(string $path)
    {
        $response = new \Slim\Psr7\Response();
        return $response->withHeader('Location', $path)->withStatus(302);
    }
}