<?php

declare(strict_types=1);

namespace Tagmark\Tests\Auth;

use Nene2\Auth\LocalBearerTokenVerifier;
use Nene2\Validation\ValidationException;
use PHPUnit\Framework\TestCase;
use Tagmark\Auth\LocalTokenIssuer;
use Tagmark\Auth\RegisterUseCase;
use Tagmark\Auth\User;
use Tagmark\Auth\UserRepositoryInterface;

final class RegisterUseCaseTest extends TestCase
{
    private UserRepositoryInterface $users;
    private RegisterUseCase $useCase;

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

        $this->useCase = new RegisterUseCase($this->users, $issuer);
    }

    public function testSuccessfulRegistration(): void
    {
        $result = $this->useCase->execute('alice@example.com', 'password123');

        $this->assertArrayHasKey('token', $result);
        $this->assertNotEmpty($result['token']);
    }

    public function testRejectsInvalidEmail(): void
    {
        $this->expectException(ValidationException::class);
        $this->useCase->execute('not-an-email', 'password123');
    }

    public function testRejectsShortPassword(): void
    {
        $this->expectException(ValidationException::class);
        $this->useCase->execute('alice@example.com', 'short');
    }

    public function testRejectsDuplicateEmail(): void
    {
        $this->useCase->execute('alice@example.com', 'password123');

        $this->expectException(ValidationException::class);
        $this->useCase->execute('alice@example.com', 'password456');
    }
}
