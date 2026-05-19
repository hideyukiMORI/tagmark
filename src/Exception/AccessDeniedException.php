<?php

declare(strict_types=1);

namespace Tagmark\Exception;

use RuntimeException;

final class AccessDeniedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Access denied.');
    }
}
