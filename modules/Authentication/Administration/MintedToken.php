<?php

declare(strict_types=1);

namespace Liminal\Module\Authentication\Administration;

/**
 * The one-time carrier of a freshly minted raw token: it exists for the
 * duration of the response that displays it and is never stored, logged or
 * reconstructed — the OneTimePassword posture.
 */
final readonly class MintedToken
{
    public function __construct(
        public int $id,
        public string $raw,
    ) {}
}
