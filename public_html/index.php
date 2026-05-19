<?php

declare(strict_types=1);

use Nene2\Auth\BearerTokenMiddleware;
use Nene2\Auth\LocalBearerTokenVerifier;
use Nene2\Error\DomainExceptionHandlerInterface;
use Nene2\Error\ErrorHandlerMiddleware;
use Nene2\Error\ProblemDetailsResponseFactory;
use Nene2\Http\JsonResponseFactory;
use Nene2\Http\ResponseEmitter;
use Nene2\Http\RuntimeApplicationFactory;
use Nene2\Middleware\CorsMiddleware;
use Nene2\Middleware\MiddlewareDispatcher;
use Nene2\Middleware\RequestIdMiddleware;
use Nene2\Middleware\RequestLoggingMiddleware;
use Nene2\Middleware\RequestSizeLimitMiddleware;
use Nene2\Middleware\SecurityHeadersMiddleware;
use Nene2\Routing\Router;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use Psr\Log\NullLogger;
use Tagmark\Auth\AuthRouteRegistrar;
use Tagmark\Auth\ExcludedPathsBearerMiddleware;
use Tagmark\Auth\LocalTokenIssuer;
use Tagmark\Auth\PdoUserRepository;
use Tagmark\Auth\LoginUseCase;
use Tagmark\Auth\RegisterUseCase;
use Tagmark\Bookmark\BookmarkRouteRegistrar;
use Tagmark\Bookmark\PdoBookmarkRepository;
use Tagmark\Exception\AccessDeniedExceptionHandler;
use Tagmark\Exception\NotFoundExceptionHandler;
use Tagmark\Tag\PdoTagRepository;
use Tagmark\Tag\TagRouteRegistrar;

require dirname(__DIR__) . '/vendor/autoload.php';

// ── Database ─────────────────────────────────────────────────────────────────

$dbFile = dirname(__DIR__) . '/tagmark.db';

if (!file_exists($dbFile)) {
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->exec((string) file_get_contents(dirname(__DIR__) . '/database/schema.sql'));
} else {
    $pdo = new PDO('sqlite:' . $dbFile);
}

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ── PSR-17 factory ────────────────────────────────────────────────────────────

$psr17 = new Psr17Factory();

// ── Auth ──────────────────────────────────────────────────────────────────────

$jwtSecret = (string) getenv('NENE2_LOCAL_JWT_SECRET');
$verifier = new LocalBearerTokenVerifier($jwtSecret);
$issuer = new LocalTokenIssuer($verifier);

$userRepo = new PdoUserRepository($pdo);

$json = new JsonResponseFactory($psr17, $psr17);
$problemDetails = new ProblemDetailsResponseFactory($psr17, $psr17);

$authRegistrar = new AuthRouteRegistrar(
    register: new RegisterUseCase($userRepo, $issuer),
    login: new LoginUseCase($userRepo, $issuer),
    json: $json,
);

// ── Repositories ──────────────────────────────────────────────────────────────

$tagRepo = new PdoTagRepository($pdo);
$bookmarkRepo = new PdoBookmarkRepository($pdo);

// ── Route registrars ─────────────────────────────────────────────────────────

$tagRegistrar = new TagRouteRegistrar(
    tags: $tagRepo,
    json: $json,
    responseFactory: $psr17,
);

$bookmarkRegistrar = new BookmarkRouteRegistrar(
    bookmarks: $bookmarkRepo,
    tags: $tagRepo,
    json: $json,
    responseFactory: $psr17,
);

// ── Exception handlers ────────────────────────────────────────────────────────

$notFoundHandler = new NotFoundExceptionHandler($problemDetails);
$accessDeniedHandler = new AccessDeniedExceptionHandler($problemDetails);

// ── BearerTokenMiddleware (protect ALL paths) wrapped with exclude list ───────
//
// NENE2 v1.4 BearerTokenMiddleware::$protectedPaths is an allowlist.
// Empty list means "protect all paths". We want to protect everything EXCEPT
// /auth/register and /auth/login. Since the factory only accepts
// ?BearerTokenMiddleware, we bypass RuntimeApplicationFactory and build the
// pipeline manually to inject ExcludedPathsBearerMiddleware instead.

$innerBearerMiddleware = new BearerTokenMiddleware(
    problemDetails: $problemDetails,
    verifier: $verifier,
    protectedPaths: [],   // empty = protect all paths
);

$bearerMiddleware = new ExcludedPathsBearerMiddleware(
    inner: $innerBearerMiddleware,
    excludedPaths: ['/auth/register', '/auth/login'],
);

// ── Router ────────────────────────────────────────────────────────────────────

$router = new Router();

$authRegistrar($router);
$tagRegistrar($router);
$bookmarkRegistrar($router);

// ── Middleware pipeline ───────────────────────────────────────────────────────

$logger = new NullLogger();

$middlewareStack = [
    new RequestIdMiddleware('X-Request-Id'),
    new RequestLoggingMiddleware($logger),
    new SecurityHeadersMiddleware(),
    new CorsMiddleware($psr17),
    new ErrorHandlerMiddleware($problemDetails, [$notFoundHandler, $accessDeniedHandler]),
    new RequestSizeLimitMiddleware($problemDetails),
    $bearerMiddleware,
];

$app = new MiddlewareDispatcher($middlewareStack, $router);

// ── Emit response ─────────────────────────────────────────────────────────────

$creator = new ServerRequestCreator($psr17, $psr17, $psr17, $psr17);
$request = $creator->fromGlobals();

$response = $app->handle($request);

(new ResponseEmitter())->emit($response);
