<?php

declare(strict_types=1);

namespace Tagmark\Auth;

use Nene2\Validation\ValidationError;
use Nene2\Validation\ValidationException;

final class RegisterUseCase
{
    public function __construct(
        private UserRepositoryInterface $users,
        private TokenIssuerInterface $issuer,
    ) {}

    /** @return array{token: string} */
    public function execute(string $email, string $password): array
    {
        $errors = [];

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = new ValidationError('email', 'Must be a valid email address.', 'invalid_email');
        }

        if (strlen($password) < 8) {
            $errors[] = new ValidationError('password', 'Must be at least 8 characters.', 'too_short');
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        if ($this->users->findByEmail($email) !== null) {
            throw new ValidationException([
                new ValidationError('email', 'Email is already registered.', 'duplicate_email'),
            ]);
        }

        $user = $this->users->create($email, password_hash($password, PASSWORD_BCRYPT));
        $token = $this->issuer->issue(['sub' => (string) $user->id, 'email' => $user->email]);

        return ['token' => $token];
    }
}
