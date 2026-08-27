<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

/**
 * Role grants live in user_roles, which is append-only in spirit: a
 * revoked grant keeps its row with revoked_at stamped, so the history
 * of who held what — and who granted it — survives.
 */
class Role
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * @return array<int, array{role_id:string, role_name:string, description:?string}>
     */
    public function all(): array
    {
        return $this->db->query(
            "SELECT role_id, role_name, description FROM roles ORDER BY role_name"
        )->fetchAll();
    }

    public function findByName(string $roleName): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM roles WHERE role_name = :role_name LIMIT 1");
        $stmt->execute(['role_name' => $roleName]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Currently-held role names, keyed by user id, for every user that
     * holds at least one. One query rather than one per user, so the
     * admin registry page stays a fixed number of round trips.
     *
     * @return array<string, array<int, string>>
     */
    public function activeRolesByUser(): array
    {
        $stmt = $this->db->query(
            "SELECT ur.user_id, r.role_name
             FROM user_roles ur
             JOIN roles r ON r.role_id = ur.role_id
             WHERE ur.revoked_at IS NULL
             ORDER BY r.role_name"
        );

        $byUser = [];
        foreach ($stmt->fetchAll() as $row) {
            $byUser[$row['user_id']][] = $row['role_name'];
        }

        return $byUser;
    }

    /**
     * User ids currently holding a named role — the "all lecturers" /
     * "all students" audiences for a notification broadcast.
     *
     * @return array<int, string>
     */
    public function activeUserIdsForRole(string $roleName): array
    {
        $stmt = $this->db->prepare(
            "SELECT ur.user_id
             FROM user_roles ur
             JOIN roles r ON r.role_id = ur.role_id
             WHERE ur.revoked_at IS NULL AND r.role_name = :role_name"
        );
        $stmt->execute(['role_name' => $roleName]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Grants a role. If the user previously held it and it was revoked,
     * that old row is reinstated rather than a duplicate inserted —
     * (user_id, role_id) is the primary key, so there can only ever be
     * one row per pair.
     */
    public function grant(string $userId, string $roleId, string $grantedBy): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO user_roles (user_id, role_id, granted_at, granted_by, revoked_at)
             VALUES (:user_id, :role_id, NOW(), :granted_by, NULL)
             ON DUPLICATE KEY UPDATE granted_at = NOW(), granted_by = VALUES(granted_by), revoked_at = NULL"
        );
        $stmt->execute([
            'user_id'    => $userId,
            'role_id'    => $roleId,
            'granted_by' => $grantedBy,
        ]);
    }

    public function revoke(string $userId, string $roleId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE user_roles SET revoked_at = NOW()
             WHERE user_id = :user_id AND role_id = :role_id AND revoked_at IS NULL"
        );
        $stmt->execute(['user_id' => $userId, 'role_id' => $roleId]);
    }

    /**
     * How many users currently hold a role. Used to refuse revoking the
     * last admin — an installation with no admin cannot grant the role
     * back to anyone, since granting it requires being one.
     */
    public function activeHolderCount(string $roleId): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM user_roles WHERE role_id = :role_id AND revoked_at IS NULL"
        );
        $stmt->execute(['role_id' => $roleId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * A role grant on its own isn't enough to use the platform — the
     * student and lecturer areas both look the user up in their own
     * profile table and bounce them to /login if there's no row. So
     * granting one of those roles provisions the matching profile if
     * it doesn't already exist.
     *
     * A newly provisioned lecturer isn't yet in internal_lecturers or
     * external_lecturers, so they won't appear in examiner invite lists
     * until that affiliation is recorded.
     */
    public function ensureProfileFor(string $userId, string $roleName): void
    {
        if ($roleName === 'lecturer') {
            $stmt = $this->db->prepare(
                "INSERT IGNORE INTO lecturers (lecturer_id, user_id) VALUES (:lecturer_id, :user_id)"
            );
            $stmt->execute(['lecturer_id' => $this->generateUuid(), 'user_id' => $userId]);
            return;
        }

        if ($roleName === 'student') {
            $stmt = $this->db->prepare(
                "INSERT IGNORE INTO students (student_id, user_id) VALUES (:student_id, :user_id)"
            );
            $stmt->execute(['student_id' => $this->generateUuid(), 'user_id' => $userId]);
        }
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
