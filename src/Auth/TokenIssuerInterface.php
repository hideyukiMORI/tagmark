<?php

declare(strict_types=1);

namespace Tagmark\Auth;

/**
 * Contract for issuing signed JWT tokens.
 *
 * Note: Nene2\Auth\TokenIssuerInterface does not exist in NENE2 v1.4.
 * LocalBearerTokenVerifier has an issue() method, but implements only TokenVerifierInterface.
 * This local interface bridges that gap.
 */
interface TokenIssuerInterface
{
    /**
     * @param array<string, mixed> $claims
     */
    public function issue(array $claims): string;
}
