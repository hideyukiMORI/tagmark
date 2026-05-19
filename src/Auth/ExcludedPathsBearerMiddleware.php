<?php

declare(strict_types=1);

namespace Tagmark\Auth;

use Nene2\Auth\BearerTokenMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Wraps BearerTokenMiddleware to support an exclude-list rather than an include-list.
 *
 * BearerTokenMiddleware::$protectedPaths is an allowlist: only those paths are protected.
 * Empty list protects every path.
 *
 * This wrapper skips authentication for paths in $excludedPaths and delegates
 * to the inner middleware for all others (with an empty protectedPaths list, meaning protect all).
 */
final readonly class ExcludedPathsBearerMiddleware implements MiddlewareInterface
{
    /** @param list<string> $excludedPaths */
    public function __construct(
        private BearerTokenMiddleware $inner,
        private array $excludedPaths,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath() ?: '/';

        if (in_array($path, $this->excludedPaths, true)) {
            return $handler->handle($request);
        }

        return $this->inner->process($request, $handler);
    }
}
