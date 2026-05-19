<?php

declare(strict_types=1);

namespace Tagmark\Auth;

interface UserRepositoryInterface
{
    public function findByEmail(string $email): ?User;

    public function create(string $email, string $passwordHash): User;
}
