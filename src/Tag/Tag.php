<?php

declare(strict_types=1);

namespace Tagmark\Tag;

final readonly class Tag
{
    public function __construct(
        public int $id,
        public int $userId,
        public string $name,
    ) {}
}
