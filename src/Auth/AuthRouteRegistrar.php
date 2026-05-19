<?php

declare(strict_types=1);

namespace Tagmark\Auth;

use Nene2\Http\JsonRequestBodyParser;
use Nene2\Http\JsonResponseFactory;
use Nene2\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class AuthRouteRegistrar
{
    public function __construct(
        private RegisterUseCase $register,
        private LoginUseCase $login,
        private JsonResponseFactory $json,
    ) {}

    public function __invoke(Router $router): void
    {
        $router->post('/auth/register', $this->handleRegister(...));
        $router->post('/auth/login', $this->handleLogin(...));
    }

    private function handleRegister(ServerRequestInterface $request): ResponseInterface
    {
        $body = JsonRequestBodyParser::parse($request);
        $email = is_string($body['email'] ?? null) ? $body['email'] : '';
        $password = is_string($body['password'] ?? null) ? $body['password'] : '';

        $result = $this->register->execute($email, $password);

        return $this->json->create($result, 201);
    }

    private function handleLogin(ServerRequestInterface $request): ResponseInterface
    {
        $body = JsonRequestBodyParser::parse($request);
        $email = is_string($body['email'] ?? null) ? $body['email'] : '';
        $password = is_string($body['password'] ?? null) ? $body['password'] : '';

        $result = $this->login->execute($email, $password);

        return $this->json->create($result);
    }
}
