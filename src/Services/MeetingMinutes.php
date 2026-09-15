<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\SupervisorShortlist;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;

/**
 * The minutes of a supervisor-request meeting as their author submits
 * them — typed minutes, a summary, and the minutes as a document — for
 * both screens that write them (the coordinator's, and a head's).
 *
 * The documents are kept in var/uploads/minutes, outside public/,
 * because the full minutes are only for the people who belong to the
 * meeting. send() is the one way out, and the caller checks who is
 * asking before using it.
 */
class MeetingMinutes
{
    public const MAX_SIZE_KB = 10240;

    /** What a file with this extension starts with. */
    private const SIGNATURES = [
        'pdf'  => '%PDF',
        'docx' => "PK\x03\x04",
        'doc'  => "\xD0\xCF\x11\xE0",
    ];

    private const CONTENT_TYPES = [
        'pdf'  => 'application/pdf',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'doc'  => 'application/msword',
    ];

    /** The only shape a stored path takes — anything else is not ours. */
    private const STORED_PATH = '~^minutes/[0-9a-f]{32}\.(pdf|docx|doc)$~';

    private SupervisorShortlist $meetings;
    private string $root;

    public function __construct(SupervisorShortlist $meetings, ?string $root = null)
    {
        $this->meetings = $meetings;
        $this->root = $root ?? dirname(__DIR__, 2) . '/var/uploads';
    }

    /**
     * Saves what the author submitted as a draft, then finalises it if
     * they asked. A document that cannot be accepted, or a finalise the
     * rules refuse, still leaves everything else saved — nothing typed
     * is lost to a missing summary or a wrong file type.
     *
     * @param array<string, mixed> $data the posted form
     * @return bool whether the minutes are now final
     */
    public function save(string $meetingId, array $data, ?UploadedFileInterface $upload): bool
    {
        $problem = $this->uploadProblem($upload);
        $stored = null;
        if ($problem === null && $upload !== null && $upload->getError() === UPLOAD_ERR_OK) {
            try {
                $stored = $this->store($upload);
            } catch (RuntimeException $e) {
                $problem = $e->getMessage();
            }
        }

        try {
            $replaced = $this->meetings->saveMinutes(
                $meetingId,
                (string) ($data['minutes'] ?? ''),
                (string) ($data['summary'] ?? ''),
                $stored,
                // A rejected upload leaves the document on file alone.
                $problem === null && ($data['remove_minutes_file'] ?? '') === '1'
            );
        } catch (\Throwable $e) {
            $this->delete($stored['path'] ?? null);
            throw $e;
        }
        $this->delete($replaced);

        if ($problem !== null) {
            throw new RuntimeException($problem . ' Everything else is saved as a draft.');
        }
        if (($data['finalize'] ?? '') !== '1') {
            return false;
        }

        try {
            $this->meetings->finalizeMinutes($meetingId);
        } catch (RuntimeException $e) {
            throw new RuntimeException('Saved as a draft, but not finalised. ' . $e->getMessage());
        }

        return true;
    }

    public function uploadProblem(?UploadedFileInterface $upload): ?string
    {
        if ($upload === null || $upload->getError() === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if (in_array($upload->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            return 'The minutes document is too large to upload.';
        }
        if ($upload->getError() !== UPLOAD_ERR_OK) {
            return 'The minutes document did not upload — please try again.';
        }
        if (!isset(self::SIGNATURES[$this->extension((string) $upload->getClientFilename())])) {
            return 'The minutes document must be a PDF or a Word document.';
        }
        if ((int) ceil((int) $upload->getSize() / 1024) > self::MAX_SIZE_KB) {
            return 'The minutes document exceeds the 10MB limit.';
        }

        return null;
    }

    /**
     * Moves an accepted upload into place and checks it really is the
     * kind of file its name says.
     *
     * @return array{name: string, path: string, size_kb: int}
     */
    public function store(UploadedFileInterface $upload): array
    {
        $name = (string) $upload->getClientFilename();
        $extension = $this->extension($name);
        $relative = 'minutes/' . bin2hex(random_bytes(16)) . '.' . $extension;
        $destination = $this->root . '/' . $relative;

        if (!is_dir(dirname($destination))) {
            mkdir(dirname($destination), 0755, true);
        }
        $upload->moveTo($destination);

        // Read once the upload has been moved: an open stream on the
        // temporary file would stop the move on Windows.
        if (file_get_contents($destination, false, null, 0, 4) !== self::SIGNATURES[$extension]) {
            @unlink($destination);
            throw new RuntimeException('The minutes document is not a real ' . strtoupper($extension) . ' file.');
        }

        return [
            'name'    => $name,
            'path'    => $relative,
            'size_kb' => (int) ceil(filesize($destination) / 1024),
        ];
    }

    public function delete(?string $relative): void
    {
        $path = $relative !== null ? $this->absolutePath($relative) : null;
        if ($path !== null && is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Hands a stored document to the browser: a PDF opens in the tab,
     * a Word document downloads.
     *
     * @param array{name: string, path: string} $document
     */
    public function send(ResponseInterface $response, array $document): ResponseInterface
    {
        $path = $this->absolutePath($document['path']);
        if ($path === null || !is_file($path)) {
            return $response->withStatus(404);
        }

        $extension = $this->extension($path);
        $name = $document['name'] !== '' ? $document['name'] : 'minutes.' . $extension;
        $fallback = preg_replace('/[^A-Za-z0-9._ -]/', '_', $name);

        $response->getBody()->write((string) file_get_contents($path));

        return $response
            ->withHeader('Content-Type', self::CONTENT_TYPES[$extension])
            ->withHeader(
                'Content-Disposition',
                ($extension === 'pdf' ? 'inline' : 'attachment')
                    . '; filename="' . $fallback . '"; filename*=UTF-8\'\'' . rawurlencode($name)
            )
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Cache-Control', 'private, no-store');
    }

    private function absolutePath(string $relative): ?string
    {
        return preg_match(self::STORED_PATH, $relative) ? $this->root . '/' . $relative : null;
    }

    private function extension(string $name): string
    {
        return strtolower(pathinfo($name, PATHINFO_EXTENSION));
    }
}
