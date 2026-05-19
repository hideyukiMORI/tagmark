<?php

declare(strict_types=1);

namespace Tagmark\Tests\Auth;

use Nene2\Auth\LocalBearerTokenVerifier;
use Nene2\Validation\ValidationException;
use PHPUnit\Framework\TestCase;
use Tagmark\Auth\LocalTokenIssuer;
use Tagmark\Auth\LoginUseCase;
use Tagmark\Auth\RegisterUseCase;
use Tagmark\Auth\User;
use Tagmark\Auth\UserRepositoryInterface;

final class LoginUseCaseTest extends TestCase
{
    private UserRepositoryInterface $users;
    private LoginUseCase $loginUseCase;

    protected function setUp(): void
    {
        $verifier = new LocalBearerTokenVerifier('test-secret-at-least-32-chars-long');
        $issuer = new LocalTokenIssuer($verifier);

        $this->users = new class implements UserRepositoryInterface {
            /** @var array<string, User> */
            public array $store = [];
            private int $nextId = 1;

            public function findByEmail(string $email): ?User
            {
                return $this->store[$email] ?? null;
            }

            public function create(string $email, string $passwordHash): User
            {
                $user = new User($this->nextId++, $email, $passwordHash);
                $this->store[$email] = $user;

                return $user;
            }
        };

        // Pre-register a user
        $register = new RegisterUseCase($this->users, $issuer);
        $register->execute('alice@example.com', 'password123');

        $this->loginUseCase = new LoginUseCase($this->users, $issuer);
    }

    public function testSuccessfulLogin(): void
    {
        $result = $this->loginUseCase->execute('alice@example.com', 'password123');

        $this->assertArrayHasKey('token', $result);
        $this->assertNotEmpty($result['token']);
    }

    public function testRejectsWrongPassword(): void
    {
        $this->expectException(ValidationException::class);
        $this->loginUseCase->execute('alice@example.com', 'wrongpassword');
    }

    public function testRejectsUnknownEmail(): void
    {
        $this->expectException(ValidationException::class);
        $this->loginUseCase->execute('unknown@example.com', 'password123');
    }
}
