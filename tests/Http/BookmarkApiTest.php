<?php

declare(strict_types=1);

namespace Tagmark\Tests\Http;

use Nene2\Auth\BearerTokenMiddleware;
use Nene2\Auth\LocalBearerTokenVerifier;
use Nene2\Error\ErrorHandlerMiddleware;
use Nene2\Error\ProblemDetailsResponseFactory;
use Nene2\Http\JsonResponseFactory;
use Nene2\Middleware\CorsMiddleware;
use Nene2\Middleware\MiddlewareDispatcher;
use Nene2\Middleware\RequestIdMiddleware;
use Nene2\Middleware\RequestSizeLimitMiddleware;
use Nene2\Middleware\SecurityHeadersMiddleware;
use Nene2\Routing\Router;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Tagmark\Auth\AuthRouteRegistrar;
use Tagmark\Auth\ExcludedPathsBearerMiddleware;
use Tagmark\Auth\LocalTokenIssuer;
use Tagmark\Auth\LoginUseCase;
use Tagmark\Auth\PdoUserRepository;
use Tagmark\Auth\RegisterUseCase;
use Tagmark\Bookmark\BookmarkRouteRegistrar;
use Tagmark\Bookmark\PdoBookmarkRepository;
use Tagmark\Exception\AccessDeniedExceptionHandler;
use Tagmark\Exception\NotFoundExceptionHandler;
use Tagmark\Tag\PdoTagRepository;
use Tagmark\Tag\TagRouteRegistrar;

final class BookmarkApiTest extends TestCase
{
    private RequestHandlerInterface $app;
    private string $token;
    private Psr17Factory $psr17;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec((string) file_get_contents(dirname(__DIR__, 2) . '/database/schema.sql'));

        $this->psr17 = new Psr17Factory();
        $secret = 'test-secret-at-least-32-characters-long';
        $verifier = new LocalBearerTokenVerifier($secret);
        $issuer = new LocalTokenIssuer($verifier);

        $userRepo = new PdoUserRepository($pdo);
        $tagRepo = new PdoTagRepository($pdo);
        $bookmarkRepo = new PdoBookmarkRepository($pdo);

        $json = new JsonResponseFactory($this->psr17, $this->psr17);
        $problemDetails = new ProblemDetailsResponseFactory($this->psr17, $this->psr17);

        $authRegistrar = new AuthRouteRegistrar(
            register: new RegisterUseCase($userRepo, $issuer),
            login: new LoginUseCase($userRepo, $issuer),
            json: $json,
        );

        $tagRegistrar = new TagRouteRegistrar(
            tags: $tagRepo,
            json: $json,
            responseFactory: $this->psr17,
        );

        $bookmarkRegistrar = new BookmarkRouteRegistrar(
            bookmarks: $bookmarkRepo,
            tags: $tagRepo,
            json: $json,
            responseFactory: $this->psr17,
        );

        $notFoundHandler = new NotFoundExceptionHandler($problemDetails);
        $accessDeniedHandler = new AccessDeniedExceptionHandler($problemDetails);

        $innerBearer = new BearerTokenMiddleware(
            problemDetails: $problemDetails,
            verifier: $verifier,
            protectedPaths: [],
        );

        $bearerMiddleware = new ExcludedPathsBearerMiddleware(
            inner: $innerBearer,
            excludedPaths: ['/auth/register', '/auth/login'],
        );

        $router = new Router();
        $authRegistrar($router);
        $tagRegistrar($router);
        $bookmarkRegistrar($router);

        $this->app = new MiddlewareDispatcher(
            [
                new RequestIdMiddleware('X-Request-Id'),
                new SecurityHeadersMiddleware(),
                new CorsMiddleware($this->psr17),
                new ErrorHandlerMiddleware($problemDetails, [$notFoundHandler, $accessDeniedHandler]),
                new RequestSizeLimitMiddleware($problemDetails),
                $bearerMiddleware,
            ],
            $router,
        );

        // Register and obtain token (no auth header yet)
        $response = $this->postPublic('/auth/register', ['email' => 'alice@example.com', 'password' => 'password123']);
        $data = $this->json($response);
        $this->token = (string) $data['token'];
    }

    public function testRegister(): void
    {
        $response = $this->postPublic('/auth/register', ['email' => 'bob@example.com', 'password' => 'password123']);

        $this->assertSame(201, $response->getStatusCode());
        $data = $this->json($response);
        $this->assertArrayHasKey('token', $data);
    }

    public function testLogin(): void
    {
        $response = $this->postPublic('/auth/login', ['email' => 'alice@example.com', 'password' => 'password123']);

        $this->assertSame(200, $response->getStatusCode());
        $data = $this->json($response);
        $this->assertArrayHasKey('token', $data);
    }

    public function testUnauthorizedWithoutToken(): void
    {
        $request = new ServerRequest('GET', '/bookmarks');
        $response = $this->app->handle($request);

        $this->assertSame(401, $response->getStatusCode());
    }

    public function testCreateAndGetBookmark(): void
    {
        $response = $this->post('/bookmarks', [
            'url' => 'https://example.com',
            'title' => 'Example',
            'memo' => 'A test',
        ]);

        $this->assertSame(201, $response->getStatusCode());
        $data = $this->json($response);
        $this->assertSame('https://example.com', $data['url']);
        $this->assertSame('Example', $data['title']);

        $id = (int) $data['id'];

        $getResponse = $this->get('/bookmarks/' . $id);
        $this->assertSame(200, $getResponse->getStatusCode());
        $getData = $this->json($getResponse);
        $this->assertArrayHasKey('tags', $getData);
        $this->assertSame([], $getData['tags']);
    }

    public function testCreateAndListTags(): void
    {
        $response = $this->post('/tags', ['name' => 'php']);
        $this->assertSame(201, $response->getStatusCode());

        $listResponse = $this->get('/tags');
        $this->assertSame(200, $listResponse->getStatusCode());
        $data = $this->json($listResponse);
        $this->assertCount(1, $data['data']);
        $this->assertSame('php', $data['data'][0]['name']);
    }

    public function testManyToManyTagOperations(): void
    {
        // Create bookmark
        $bResp = $this->post('/bookmarks', [
            'url' => 'https://php.net',
            'title' => 'PHP',
            'memo' => '',
        ]);
        $bookmarkId = (int) $this->json($bResp)['id'];

        // Create tag
        $tResp = $this->post('/tags', ['name' => 'php']);
        $tagId = (int) $this->json($tResp)['id'];

        // Attach tag
        $attachResp = $this->postNoBody('/bookmarks/' . $bookmarkId . '/tags/' . $tagId);
        $this->assertSame(200, $attachResp->getStatusCode());
        $attachData = $this->json($attachResp);
        $this->assertCount(1, $attachData['tags']);
        $this->assertSame('php', $attachData['tags'][0]['name']);

        // Idempotent attach
        $attachResp2 = $this->postNoBody('/bookmarks/' . $bookmarkId . '/tags/' . $tagId);
        $this->assertSame(200, $attachResp2->getStatusCode());

        // Get bookmark shows tag
        $getResp = $this->get('/bookmarks/' . $bookmarkId);
        $this->assertCount(1, $this->json($getResp)['tags']);

        // Filter bookmarks by tag
        $listResp = $this->get('/bookmarks?tag=' . $tagId);
        $this->assertSame(200, $listResp->getStatusCode());
        $listData = $this->json($listResp);
        $this->assertCount(1, $listData['data']);

        // Detach tag
        $detachResp = $this->delete('/bookmarks/' . $bookmarkId . '/tags/' . $tagId);
        $this->assertSame(204, $detachResp->getStatusCode());

        // Tag should be gone from bookmark
        $afterDetach = $this->json($this->get('/bookmarks/' . $bookmarkId));
        $this->assertCount(0, $afterDetach['tags']);
    }

    public function testDeleteBookmarkCascadesBookmarkTags(): void
    {
        $bId = (int) $this->json($this->post('/bookmarks', ['url' => 'https://x.com', 'title' => 'X', 'memo' => '']))['id'];
        $tId = (int) $this->json($this->post('/tags', ['name' => 'social']))['id'];

        $this->postNoBody('/bookmarks/' . $bId . '/tags/' . $tId);

        // Verify tag is attached
        $this->assertCount(1, $this->json($this->get('/bookmarks/' . $bId))['tags']);

        // Delete bookmark
        $deleteResp = $this->delete('/bookmarks/' . $bId);
        $this->assertSame(204, $deleteResp->getStatusCode());

        // Should be 404 now
        $this->assertSame(404, $this->get('/bookmarks/' . $bId)->getStatusCode());
    }

    public function testDeleteTagCascadesBookmarkTags(): void
    {
        $bId = (int) $this->json($this->post('/bookmarks', ['url' => 'https://y.com', 'title' => 'Y', 'memo' => '']))['id'];
        $tId = (int) $this->json($this->post('/tags', ['name' => 'news']))['id'];

        $this->postNoBody('/bookmarks/' . $bId . '/tags/' . $tId);
        $this->assertCount(1, $this->json($this->get('/bookmarks/' . $bId))['tags']);

        // Delete tag
        $deleteTagResp = $this->delete('/tags/' . $tId);
        $this->assertSame(204, $deleteTagResp->getStatusCode());

        // Bookmark should have no tags now
        $this->assertCount(0, $this->json($this->get('/bookmarks/' . $bId))['tags']);
    }

    public function testOwnershipCheck403(): void
    {
        // Register second user
        $resp2 = $this->postPublic('/auth/register', ['email' => 'bob@example.com', 'password' => 'password123']);
        $token2 = (string) $this->json($resp2)['token'];

        // Create bookmark as alice
        $bId = (int) $this->json($this->post('/bookmarks', ['url' => 'https://alice.com', 'title' => 'Alice', 'memo' => '']))['id'];

        // Bob tries to access Alice's bookmark
        $request = new ServerRequest('GET', '/bookmarks/' . $bId, [
            'Authorization' => 'Bearer ' . $token2,
        ]);
        $response = $this->app->handle($request);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testNotFound404(): void
    {
        $response = $this->get('/bookmarks/99999');
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testUpdateBookmark(): void
    {
        $bId = (int) $this->json($this->post('/bookmarks', ['url' => 'https://old.com', 'title' => 'Old', 'memo' => '']))['id'];

        $response = $this->put('/bookmarks/' . $bId, ['url' => 'https://new.com', 'title' => 'New', 'memo' => 'Updated']);
        $this->assertSame(200, $response->getStatusCode());
        $data = $this->json($response);
        $this->assertSame('https://new.com', $data['url']);
        $this->assertSame('New', $data['title']);
    }

    public function testValidationError422(): void
    {
        $response = $this->post('/bookmarks', ['url' => '', 'title' => '', 'memo' => '']);
        $this->assertSame(422, $response->getStatusCode());
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $body */
    private function postPublic(string $path, array $body): ResponseInterface
    {
        $request = new ServerRequest('POST', $path, [
            'Content-Type' => 'application/json',
        ]);
        $request = $request->withBody(
            $this->psr17->createStream((string) json_encode($body, JSON_THROW_ON_ERROR))
        );

        return $this->app->handle($request);
    }

    /** @param array<string, mixed> $body */
    private function post(string $path, array $body): ResponseInterface
    {
        $request = new ServerRequest('POST', $path, [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->token,
        ]);
        $request = $request->withBody(
            $this->psr17->createStream((string) json_encode($body, JSON_THROW_ON_ERROR))
        );

        return $this->app->handle($request);
    }

    private function postNoBody(string $path): ResponseInterface
    {
        $request = new ServerRequest('POST', $path, [
            'Authorization' => 'Bearer ' . $this->token,
        ]);

        return $this->app->handle($request);
    }

    private function get(string $path): ResponseInterface
    {
        $request = new ServerRequest('GET', $path, [
            'Authorization' => 'Bearer ' . $this->token,
        ]);

        return $this->app->handle($request);
    }

    /** @param array<string, mixed> $body */
    private function put(string $path, array $body): ResponseInterface
    {
        $request = new ServerRequest('PUT', $path, [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->token,
        ]);
        $request = $request->withBody(
            $this->psr17->createStream((string) json_encode($body, JSON_THROW_ON_ERROR))
        );

        return $this->app->handle($request);
    }

    private function delete(string $path): ResponseInterface
    {
        $request = new ServerRequest('DELETE', $path, [
            'Authorization' => 'Bearer ' . $this->token,
        ]);

        return $this->app->handle($request);
    }

    /** @return array<string, mixed> */
    private function json(ResponseInterface $response): array
    {
        /** @var array<string, mixed> */
        return (array) json_decode((string) $response->getBody(), associative: true, flags: JSON_THROW_ON_ERROR);
    }
}
