<?php

declare(strict_types=1);

namespace Tagmark\Tag;

use PDO;

final readonly class PdoTagRepository implements TagRepositoryInterface
{
    public function __construct(private PDO $pdo) {}

    public function findById(int $id): ?Tag
    {
        $stmt = $this->pdo->prepare('SELECT id, user_id, name FROM tags WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return $this->hydrate($row);
    }

    /** @return list<Tag> */
    public function findByUserId(int $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT id, user_id, name FROM tags WHERE user_id = ? ORDER BY name ASC');
        $stmt->execute([$userId]);

        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map($this->hydrate(...), $rows);
    }

    public function create(int $userId, string $name): Tag
    {
        $stmt = $this->pdo->prepare('INSERT INTO tags (user_id, name) VALUES (?, ?)');
        $stmt->execute([$userId, $name]);
        $id = (int) $this->pdo->lastInsertId();

        return new Tag(id: $id, userId: $userId, name: $name);
    }

    public function delete(int $id): void
    {
        // Cascade: remove join table rows first
        $stmt = $this->pdo->prepare('DELETE FROM bookmark_tags WHERE tag_id = ?');
        $stmt->execute([$id]);

        $stmt = $this->pdo->prepare('DELETE FROM tags WHERE id = ?');
        $stmt->execute([$id]);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Tag
    {
        return new Tag(
            id: (int) $row['id'],
            userId: (int) $row['user_id'],
            name: (string) $row['name'],
        );
    }
}
