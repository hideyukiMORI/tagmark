<?php

declare(strict_types=1);

namespace Tagmark\Auth;

use PDO;

final readonly class PdoUserRepository implements UserRepositoryInterface
{
    public function __construct(private PDO $pdo) {}

    public function findByEmail(string $email): ?User
    {
        $stmt = $this->pdo->prepare('SELECT id, email, password_hash FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return new User(
            id: (int) $row['id'],
            email: (string) $row['email'],
            passwordHash: (string) $row['password_hash'],
        );
    }

    public function create(string $email, string $passwordHash): User
    {
        $stmt = $this->pdo->prepare('INSERT INTO users (email, password_hash) VALUES (?, ?)');
        $stmt->execute([$email, $passwordHash]);
        $id = (int) $this->pdo->lastInsertId();

        return new User(id: $id, email: $email, passwordHash: $passwordHash);
    }
}
