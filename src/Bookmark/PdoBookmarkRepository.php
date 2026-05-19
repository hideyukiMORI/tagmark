<?php

declare(strict_types=1);

namespace Tagmark\Bookmark;

use PDO;

final readonly class PdoBookmarkRepository implements BookmarkRepositoryInterface
{
    public function __construct(private PDO $pdo) {}

    public function findById(int $id): ?Bookmark
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, user_id, url, title, memo FROM bookmarks WHERE id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return $this->hydrate($row);
    }

    /** @return list<Bookmark> */
    public function findByUserId(int $userId, int $limit, int $offset, ?int $tagId = null): array
    {
        if ($tagId !== null) {
            $stmt = $this->pdo->prepare(
                'SELECT b.id, b.user_id, b.url, b.title, b.memo
                 FROM bookmarks b
                 INNER JOIN bookmark_tags bt ON bt.bookmark_id = b.id
                 WHERE b.user_id = ? AND bt.tag_id = ?
                 ORDER BY b.id DESC
                 LIMIT ? OFFSET ?'
            );
            $stmt->execute([$userId, $tagId, $limit, $offset]);
        } else {
            $stmt = $this->pdo->prepare(
                'SELECT id, user_id, url, title, memo FROM bookmarks
                 WHERE user_id = ?
                 ORDER BY id DESC
                 LIMIT ? OFFSET ?'
            );
            $stmt->execute([$userId, $limit, $offset]);
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map($this->hydrate(...), $rows);
    }

    public function create(int $userId, string $url, string $title, string $memo): Bookmark
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO bookmarks (user_id, url, title, memo) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$userId, $url, $title, $memo]);
        $id = (int) $this->pdo->lastInsertId();

        return new Bookmark(id: $id, userId: $userId, url: $url, title: $title, memo: $memo);
    }

    public function update(int $id, string $url, string $title, string $memo): Bookmark
    {
        $stmt = $this->pdo->prepare(
            'UPDATE bookmarks SET url = ?, title = ?, memo = ? WHERE id = ?'
        );
        $stmt->execute([$url, $title, $memo, $id]);

        $bookmark = $this->findById($id);

        if ($bookmark === null) {
            throw new \RuntimeException(sprintf('Bookmark #%d disappeared after update.', $id));
        }

        return $bookmark;
    }

    public function delete(int $id): void
    {
        // Cascade: remove join table rows first
        $stmt = $this->pdo->prepare('DELETE FROM bookmark_tags WHERE bookmark_id = ?');
        $stmt->execute([$id]);

        $stmt = $this->pdo->prepare('DELETE FROM bookmarks WHERE id = ?');
        $stmt->execute([$id]);
    }

    /** @return list<array{id: int, name: string}> */
    public function findTagsForBookmark(int $bookmarkId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT t.id, t.name FROM tags t
             INNER JOIN bookmark_tags bt ON bt.tag_id = t.id
             WHERE bt.bookmark_id = ?
             ORDER BY t.name ASC'
        );
        $stmt->execute([$bookmarkId]);

        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(
            static fn(array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name']],
            $rows,
        );
    }

    public function attachTag(int $bookmarkId, int $tagId): void
    {
        // Idempotent: INSERT OR IGNORE in SQLite
        $stmt = $this->pdo->prepare(
            'INSERT OR IGNORE INTO bookmark_tags (bookmark_id, tag_id) VALUES (?, ?)'
        );
        $stmt->execute([$bookmarkId, $tagId]);
    }

    public function detachTag(int $bookmarkId, int $tagId): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM bookmark_tags WHERE bookmark_id = ? AND tag_id = ?'
        );
        $stmt->execute([$bookmarkId, $tagId]);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Bookmark
    {
        return new Bookmark(
            id: (int) $row['id'],
            userId: (int) $row['user_id'],
            url: (string) $row['url'],
            title: (string) $row['title'],
            memo: (string) $row['memo'],
        );
    }
}
