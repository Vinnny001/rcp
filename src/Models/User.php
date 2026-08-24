<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

class User
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function findByUsername(string $username): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM users WHERE username = :username LIMIT 1"
        );
        $stmt->execute(['username' => $username]);
        $user = $stmt->fetch();
        return $user ?: null;
    }

    public function findByEmail(string $email): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM users WHERE email = :email LIMIT 1"
        );
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();
        return $user ?: null;
    }

        public function findByEmailOrStudentNumber(string $identifier): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT 
                u.*,
                s.student_number,
                s.student_email
             FROM users u
             LEFT JOIN students s ON s.user_id = u.user_id
             WHERE u.email = :identifier
                OR s.student_number = :identifier
             LIMIT 1"
        );

        $stmt->execute(['identifier' => $identifier]);
        $user = $stmt->fetch();

        return $user ?: null;
    }

    public function findById(string $userId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM users WHERE user_id = :user_id LIMIT 1"
        );
        $stmt->execute(['user_id' => $userId]);
        $user = $stmt->fetch();
        return $user ?: null;
    }

    /**
     * Active (non-revoked) role names for a user — e.g. ['lecturer', 'admin'].
     * Used at login to check whether the role a user is attempting to
     * log in as is one they currently hold, and elsewhere to determine
     * what a user can do without relying on a single fixed role column.
     */
    public function activeRoles(string $userId): array
    {
        $stmt = $this->db->prepare(
            "SELECT r.role_name
             FROM user_roles ur
             JOIN roles r ON r.role_id = ur.role_id
             WHERE ur.user_id = :user_id AND ur.revoked_at IS NULL"
        );
        $stmt->execute(['user_id' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Creates a user row (no role column anymore — roles are granted
     * separately via assignRole()) and returns the new user_id.
     */
    public function create(array $data): string
    {
        $stmt = $this->db->prepare(
            "INSERT INTO users (user_id, username, email, password_hash, first_name, last_name, phone)
             VALUES (:user_id, :username, :email, :password_hash, :first_name, :last_name, :phone)"
        );

        $userId = $this->generateUuid();

        $stmt->execute([
            'user_id'       => $userId,
            'username'      => $data['username'],
            'email'         => $data['email'],
            'password_hash' => password_hash($data['password'], PASSWORD_BCRYPT),
            'first_name'    => $data['first_name'],
            'last_name'     => $data['last_name'],
            'phone'         => $data['phone'] ?? null,
        ]);

        return $userId;
    }

    /**
     * Grants a role to a user by role name (e.g. 'student', 'lecturer').
     * Looks up the role_id from roles, then inserts into user_roles.
     * $grantedBy is nullable — self-registration (e.g. a new student
     * signing up) has no admin granting the role, so this is null in
     * that case rather than pointing at a user who didn't actually grant it.
     */
    public function assignRole(string $userId, string $roleName, ?string $grantedBy = null): void
    {
        $stmt = $this->db->prepare("SELECT role_id FROM roles WHERE role_name = :role_name LIMIT 1");
        $stmt->execute(['role_name' => $roleName]);
        $roleId = $stmt->fetchColumn();

        if (!$roleId) {
            throw new \RuntimeException("Role '{$roleName}' does not exist in the roles table.");
        }

        $insert = $this->db->prepare(
            "INSERT INTO user_roles (user_id, role_id, granted_at, granted_by)
             VALUES (:user_id, :role_id, NOW(), :granted_by)"
        );
        $insert->execute([
            'user_id'    => $userId,
            'role_id'    => $roleId,
            'granted_by' => $grantedBy,
        ]);
    }

    public function createStudent(array $userData, array $studentData, Student $studentModel): string
    {
        $this->db->beginTransaction();

        try {
            $userId = $this->create($userData);
            $this->assignRole($userId, 'student');
            $studentModel->create($userId, $studentData);
            $this->db->commit();
            return $userId;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function updateLastLogin(string $userId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE users SET last_login = NOW() WHERE user_id = :user_id"
        );
        $stmt->execute(['user_id' => $userId]);
    }

    public function verifyPassword(string $plainPassword, string $hash): bool
    {
        return password_verify($plainPassword, $hash);
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}