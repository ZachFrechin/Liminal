<?php

declare(strict_types=1);

namespace Liminal\Tests\Unit\Security\Double;

use Liminal\Lib\Security\Authentication\ClientContext;
use Liminal\Lib\Security\Contract\LoginThrottle;

/**
 * Records which throttle calls the Authenticator makes, and can pretend to be
 * holding an identifier.
 */
final class RecordingThrottle implements LoginThrottle
{
    /** @var list<string> */
    public array $calls = [];

    public function __construct(private readonly ?int $retryAfter = null) {}

    public function check(string $identifier, ClientContext $client): ?int
    {
        return $this->retryAfter;
    }

    public function recordFailure(string $identifier, ClientContext $client): void
    {
        $this->calls[] = 'failure:' . $identifier;
    }

    public function recordSuccess(string $identifier, ClientContext $client): void
    {
        $this->calls[] = 'success:' . $identifier;
    }
}
