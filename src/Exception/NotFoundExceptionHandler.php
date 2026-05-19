<?php

declare(strict_types=1);

namespace Tagmark\Exception;

use Nene2\Error\DomainExceptionHandlerInterface;
use Nene2\Error\ProblemDetailsResponseFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

final readonly class NotFoundExceptionHandler implements DomainExceptionHandlerInterface
{
    public function __construct(private ProblemDetailsResponseFactory $problemDetails) {}

    public function supports(Throwable $exception): bool
    {
        return $exception instanceof NotFoundException;
    }

    public function handle(Throwable $exception, ServerRequestInterface $request): ResponseInterface
    {
        return $this->problemDetails->create(
            $request,
            'not-found',
            'Not Found',
            404,
            $exception->getMessage(),
        );
    }
}
