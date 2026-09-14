<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The supervisor list used to be its own page, chosen after the proposal
 * was submitted. It is now written and sent together with the proposal
 * on /student/proposal, so this only sends old links and bookmarks there.
 */
class StudentSupervisorsController
{
    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $response->withHeader('Location', '/student/proposal')->withStatus(302);
    }

    /**
     * A form still posting here predates the change and cannot carry a
     * proposal with it, so nothing is saved.
     */
    public function submit(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $_SESSION['flash_error'] = 'Supervisors are now chosen on this page and sent together with your proposal.';

        return $response->withHeader('Location', '/student/proposal')->withStatus(302);
    }
}
