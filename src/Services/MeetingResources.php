<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * Resources attached to a meeting: documents and links every attendee
 * can open, which nobody scores. Shared by the supervisor's meeting
 * scheduler and the coordinator's exam scheduler, so both accept the same
 * things — a document only from the people allowed to share it, a link
 * only if it is a real http(s) URL.
 */
class MeetingResources
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * A user's own documents, to offer as resources.
     *
     * @return array<int, array{document_id: string, file_name: string, doc_type_name: string}>
     */
    public function documentsOwnedBy(string $userId): array
    {
        $stmt = $this->db->prepare(
            "SELECT d.document_id, d.file_name, dt.doc_type_name
             FROM documents d
             JOIN document_types dt ON dt.doc_type_id = d.document_type_id
             WHERE d.user_id = :user_id
             ORDER BY d.uploaded_at DESC"
        );
        $stmt->execute(['user_id' => $userId]);

        return $stmt->fetchAll();
    }

    /**
     * The posted document ids that belong to one of these users. Anything
     * else — another student's document, say — is dropped rather than
     * trusted from the request.
     *
     * @param array<int, string> $candidateIds
     * @param array<int, string|null> $ownerUserIds
     * @return array<int, string>
     */
    public function ownedDocumentIds(array $candidateIds, array $ownerUserIds): array
    {
        $candidateIds = array_values(array_filter($candidateIds, fn ($id): bool => is_string($id) && $id !== ''));
        $ownerUserIds = array_values(array_filter($ownerUserIds));
        if ($candidateIds === [] || $ownerUserIds === []) {
            return [];
        }

        $stmt = $this->db->prepare(
            "SELECT document_id FROM documents
             WHERE document_id IN (" . implode(',', array_fill(0, count($candidateIds), '?')) . ")
               AND user_id IN (" . implode(',', array_fill(0, count($ownerUserIds), '?')) . ")"
        );
        $stmt->execute(array_merge($candidateIds, $ownerUserIds));

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Parallel resource_links[] / resource_link_labels[] from a form, as
     * [{url, label}], keeping only well-formed http(s) URLs — a
     * javascript: or data: URL never reaches the database, since it is
     * rendered later as a plain href.
     *
     * @param array<string, mixed> $data
     * @return array<int, array{url: string, label: ?string}>
     */
    public static function parseLinks(array $data): array
    {
        $urls = (array) ($data['resource_links'] ?? []);
        $labels = (array) ($data['resource_link_labels'] ?? []);
        $links = [];

        foreach ($urls as $i => $url) {
            $url = trim((string) $url);
            if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
                continue;
            }
            if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
                continue;
            }

            $links[] = [
                'url'   => $url,
                'label' => trim((string) ($labels[$i] ?? '')) ?: null,
            ];
        }

        return $links;
    }
}
