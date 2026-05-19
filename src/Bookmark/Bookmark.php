<?php

declare(strict_types=1);

namespace Tagmark\Bookmark;

final readonly class Bookmark
{
    public function __construct(
        public int $id,
        public int $userId,
        public string $url,
        public string $title,
        public string $memo,
    ) {}
}
