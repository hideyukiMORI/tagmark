<?php

declare(strict_types=1);

namespace Tagmark\Exception;

use RuntimeException;

final class NotFoundException extends RuntimeException
{
    public function __construct(string $resource, int $id)
    {
        parent::__construct(sprintf('%s #%d not found.', $resource, $id));
    }
}
