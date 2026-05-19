<?php

declare(strict_types=1);

namespace Tagmark\Bookmark;

use Nene2\Http\JsonRequestBodyParser;
use Nene2\Http\JsonResponseFactory;
use Nene2\Http\PaginationQueryParser;
use Nene2\Routing\Router;
use Nene2\Validation\ValidationError;
use Nene2\Validation\ValidationException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Tagmark\Exception\AccessDeniedException;
use Tagmark\Exception\NotFoundException;
use Tagmark\Tag\TagRepositoryInterface;

final class BookmarkRouteRegistrar
{
    public function __construct(
        private BookmarkRepositoryInterface $bookmarks,
        private TagRepositoryInterface $tags,
        private JsonResponseFactory $json,
        private ResponseFactoryInterface $responseFactory,
    ) {}

    public function __invoke(Router $router): void
    {
        $router->get('/bookmarks', $this->handleList(...));
        $router->post('/bookmarks', $this->handleCreate(...));
        $router->get('/bookmarks/{id}', $this->handleGet(...));
        $router->put('/bookmarks/{id}', $this->handleUpdate(...));
        $router->delete('/bookmarks/{id}', $this->handleDelete(...));
        $router->post('/bookmarks/{id}/tags/{tagId}', $this->handleAttachTag(...));
        $router->delete('/bookmarks/{id}/tags/{tagId}', $this->handleDetachTag(...));
    }

    private function handleList(ServerRequestInterface $request): ResponseInterface
    {
        $claims = $request->getAttribute('nene2.auth.claims');
        $userId = (int) ($claims['sub'] ?? 0);

        $pagination = PaginationQueryParser::parse($request);

        $query = $request->getQueryParams();
        $tagId = isset($query['tag']) ? (int) $query['tag'] : null;

        $bookmarks = $this->bookmarks->findByUserId(
            $userId,
            $pagination->limit,
            $pagination->offset,
            $tagId,
        );

        return $this->json->create([
            'data' => array_map(
                fn(Bookmark $b): array => $this->serializeBookmark($b),
                $bookmarks,
            ),
            'limit' => $pagination->limit,
            'offset' => $pagination->offset,
        ]);
    }

    private function handleCreate(ServerRequestInterface $request): ResponseInterface
    {
        $claims = $request->getAttribute('nene2.auth.claims');
        $userId = (int) ($claims['sub'] ?? 0);

        $body = JsonRequestBodyParser::parse($request);
        [$url, $title, $memo] = $this->extractBookmarkFields($body);

        $bookmark = $this->bookmarks->create($userId, $url, $title, $memo);

        return $this->json->create($this->serializeBookmark($bookmark), 201);
    }

    private function handleGet(ServerRequestInterface $request): ResponseInterface
    {
        $claims = $request->getAttribute('nene2.auth.claims');
        $userId = (int) ($claims['sub'] ?? 0);

        $params = $request->getAttribute(Router::PARAMETERS_ATTRIBUTE, []);
        $id = (int) ($params['id'] ?? 0);

        $bookmark = $this->bookmarks->findById($id);

        if ($bookmark === null) {
            throw new NotFoundException('Bookmark', $id);
        }

        if ($bookmark->userId !== $userId) {
            throw new AccessDeniedException();
        }

        $tags = $this->bookmarks->findTagsForBookmark($id);

        return $this->json->create(array_merge($this->serializeBookmark($bookmark), ['tags' => $tags]));
    }

    private function handleUpdate(ServerRequestInterface $request): ResponseInterface
    {
        $claims = $request->getAttribute('nene2.auth.claims');
        $userId = (int) ($claims['sub'] ?? 0);

        $params = $request->getAttribute(Router::PARAMETERS_ATTRIBUTE, []);
        $id = (int) ($params['id'] ?? 0);

        $bookmark = $this->bookmarks->findById($id);

        if ($bookmark === null) {
            throw new NotFoundException('Bookmark', $id);
        }

        if ($bookmark->userId !== $userId) {
            throw new AccessDeniedException();
        }

        $body = JsonRequestBodyParser::parse($request);
        [$url, $title, $memo] = $this->extractBookmarkFields($body);

        $updated = $this->bookmarks->update($id, $url, $title, $memo);

        return $this->json->create($this->serializeBookmark($updated));
    }

    private function handleDelete(ServerRequestInterface $request): ResponseInterface
    {
        $claims = $request->getAttribute('nene2.auth.claims');
        $userId = (int) ($claims['sub'] ?? 0);

        $params = $request->getAttribute(Router::PARAMETERS_ATTRIBUTE, []);
        $id = (int) ($params['id'] ?? 0);

        $bookmark = $this->bookmarks->findById($id);

        if ($bookmark === null) {
            throw new NotFoundException('Bookmark', $id);
        }

        if ($bookmark->userId !== $userId) {
            throw new AccessDeniedException();
        }

        $this->bookmarks->delete($id);

        return $this->responseFactory->createResponse(204);
    }

    private function handleAttachTag(ServerRequestInterface $request): ResponseInterface
    {
        $claims = $request->getAttribute('nene2.auth.claims');
        $userId = (int) ($claims['sub'] ?? 0);

        $params = $request->getAttribute(Router::PARAMETERS_ATTRIBUTE, []);
        $bookmarkId = (int) ($params['id'] ?? 0);
        $tagId = (int) ($params['tagId'] ?? 0);

        $bookmark = $this->bookmarks->findById($bookmarkId);

        if ($bookmark === null) {
            throw new NotFoundException('Bookmark', $bookmarkId);
        }

        if ($bookmark->userId !== $userId) {
            throw new AccessDeniedException();
        }

        $tag = $this->tags->findById($tagId);

        if ($tag === null) {
            throw new NotFoundException('Tag', $tagId);
        }

        if ($tag->userId !== $userId) {
            throw new AccessDeniedException();
        }

        $this->bookmarks->attachTag($bookmarkId, $tagId);

        $tags = $this->bookmarks->findTagsForBookmark($bookmarkId);

        return $this->json->create(array_merge($this->serializeBookmark($bookmark), ['tags' => $tags]));
    }

    private function handleDetachTag(ServerRequestInterface $request): ResponseInterface
    {
        $claims = $request->getAttribute('nene2.auth.claims');
        $userId = (int) ($claims['sub'] ?? 0);

        $params = $request->getAttribute(Router::PARAMETERS_ATTRIBUTE, []);
        $bookmarkId = (int) ($params['id'] ?? 0);
        $tagId = (int) ($params['tagId'] ?? 0);

        $bookmark = $this->bookmarks->findById($bookmarkId);

        if ($bookmark === null) {
            throw new NotFoundException('Bookmark', $bookmarkId);
        }

        if ($bookmark->userId !== $userId) {
            throw new AccessDeniedException();
        }

        $this->bookmarks->detachTag($bookmarkId, $tagId);

        return $this->responseFactory->createResponse(204);
    }

    /**
     * @param array<string, mixed> $body
     * @return array{0: string, 1: string, 2: string}
     */
    private function extractBookmarkFields(array $body): array
    {
        $url = is_string($body['url'] ?? null) ? trim($body['url']) : '';
        $title = is_string($body['title'] ?? null) ? trim($body['title']) : '';
        $memo = is_string($body['memo'] ?? null) ? trim($body['memo']) : '';

        $errors = [];

        if ($url === '') {
            $errors[] = new ValidationError('url', 'URL must not be empty.', 'required');
        }

        if ($title === '') {
            $errors[] = new ValidationError('title', 'Title must not be empty.', 'required');
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return [$url, $title, $memo];
    }

    /** @return array{id: int, user_id: int, url: string, title: string, memo: string} */
    private function serializeBookmark(Bookmark $bookmark): array
    {
        return [
            'id' => $bookmark->id,
            'user_id' => $bookmark->userId,
            'url' => $bookmark->url,
            'title' => $bookmark->title,
            'memo' => $bookmark->memo,
        ];
    }
}
