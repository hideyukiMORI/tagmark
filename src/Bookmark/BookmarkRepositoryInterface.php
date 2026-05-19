<?php

declare(strict_types=1);

namespace Tagmark\Bookmark;

interface BookmarkRepositoryInterface
{
    public function findById(int $id): ?Bookmark;

    /**
     * @return list<Bookmark>
     */
    public function findByUserId(int $userId, int $limit, int $offset, ?int $tagId = null): array;

    public function create(int $userId, string $url, string $title, string $memo): Bookmark;

    public function update(int $id, string $url, string $title, string $memo): Bookmark;

    public function delete(int $id): void;

    /**
     * @return list<array{id: int, name: string}>
     */
    public function findTagsForBookmark(int $bookmarkId): array;

    public function attachTag(int $bookmarkId, int $tagId): void;

    public function detachTag(int $bookmarkId, int $tagId): void;
}
