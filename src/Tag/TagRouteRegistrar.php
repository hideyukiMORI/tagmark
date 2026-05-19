<?php

declare(strict_types=1);

namespace Tagmark\Tag;

use Nene2\Http\JsonRequestBodyParser;
use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Nene2\Validation\ValidationError;
use Nene2\Validation\ValidationException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Tagmark\Exception\AccessDeniedException;
use Tagmark\Exception\NotFoundException;

final class TagRouteRegistrar
{
    public function __construct(
        private TagRepositoryInterface $tags,
        private JsonResponseFactory $json,
        private ResponseFactoryInterface $responseFactory,
    ) {}

    public function __invoke(Router $router): void
    {
        $router->get('/tags', $this->handleList(...));
        $router->post('/tags', $this->handleCreate(...));
        $router->delete('/tags/{id}', $this->handleDelete(...));
    }

    private function handleList(ServerRequestInterface $request): ResponseInterface
    {
        $claims = $request->getAttribute('nene2.auth.claims');
        $userId = (int) ($claims['sub'] ?? 0);

        $tags = $this->tags->findByUserId($userId);

        return $this->json->create([
            'data' => array_map($this->serializeTag(...), $tags),
        ]);
    }

    private function handleCreate(ServerRequestInterface $request): ResponseInterface
    {
        $claims = $request->getAttribute('nene2.auth.claims');
        $userId = (int) ($claims['sub'] ?? 0);

        $body = JsonRequestBodyParser::parse($request);
        $name = is_string($body['name'] ?? null) ? trim($body['name']) : '';

        if ($name === '') {
            throw new ValidationException([
                new ValidationError('name', 'Tag name must not be empty.', 'required'),
            ]);
        }

        $tag = $this->tags->create($userId, $name);

        return $this->json->create($this->serializeTag($tag), 201);
    }

    private function handleDelete(ServerRequestInterface $request): ResponseInterface
    {
        $claims = $request->getAttribute('nene2.auth.claims');
        $userId = (int) ($claims['sub'] ?? 0);

        $params = $request->getAttribute(Router::PARAMETERS_ATTRIBUTE, []);
        $id = (int) ($params['id'] ?? 0);

        $tag = $this->tags->findById($id);

        if ($tag === null) {
            throw new NotFoundException('Tag', $id);
        }

        if ($tag->userId !== $userId) {
            throw new AccessDeniedException();
        }

        $this->tags->delete($id);

        return $this->responseFactory->createResponse(204);
    }

    /** @return array{id: int, user_id: int, name: string} */
    private function serializeTag(Tag $tag): array
    {
        return [
            'id' => $tag->id,
            'user_id' => $tag->userId,
            'name' => $tag->name,
        ];
    }
}
