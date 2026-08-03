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

    public function findById(string $userId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM users WHERE user_id = :user_id LIMIT 1"
        );
        $stmt->execute(['user_id' => $userId]);
        $user = $stmt->fetch();
        return $user ?: null;
    }

    public function create(array $data): string
    {
        $stmt = $this->db->prepare(
            "INSERT INTO users (username, email, password_hash, role, first_name, last_name, phone)
             VALUES (:username, :email, :password_hash, :role, :first_name, :last_name, :phone)"
        );

        $stmt->execute([
            'username'      => $data['username'],
            'email'         => $data['email'],
            'password_hash' => password_hash($data['password'], PASSWORD_BCRYPT),
            'role'          => $data['role'],
            'first_name'    => $data['first_name'],
            'last_name'     => $data['last_name'],
            'phone'         => $data['phone'] ?? null,
        ]);

        // MySQL generates the UUID via DEFAULT (uuid()), so fetch it back
        return $this->findByUsername($data['username'])['user_id'];
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
}