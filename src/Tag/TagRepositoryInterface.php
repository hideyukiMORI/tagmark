<?php

declare(strict_types=1);

namespace Tagmark\Tag;

interface TagRepositoryInterface
{
    public function findById(int $id): ?Tag;

    /** @return list<Tag> */
    public function findByUserId(int $userId): array;

    public function create(int $userId, string $name): Tag;

    public function delete(int $id): void;
}
