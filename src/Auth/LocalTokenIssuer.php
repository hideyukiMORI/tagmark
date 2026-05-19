<?php

declare(strict_types=1);

namespace Tagmark\Auth;

use Nene2\Auth\LocalBearerTokenVerifier;

/**
 * Adapts LocalBearerTokenVerifier's issue() method to TokenIssuerInterface.
 *
 * LocalBearerTokenVerifier has an issue() method but does not implement a
 * TokenIssuerInterface (which does not exist in NENE2 v1.4). This adapter
 * bridges that gap without depending on internal implementation details.
 */
final readonly class LocalTokenIssuer implements TokenIssuerInterface
{
    public function __construct(private LocalBearerTokenVerifier $verifier) {}

    /** @param array<string, mixed> $claims */
    public function issue(array $claims): string
    {
        return $this->verifier->issue($claims);
    }
}
