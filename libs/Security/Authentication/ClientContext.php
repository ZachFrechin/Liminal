<?php

declare(strict_types=1);

namespace Liminal\Lib\Security\Authentication;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Who is knocking, as far as the transport can honestly say.
 *
 * The address comes from REMOTE_ADDR only. X-Forwarded-For is attacker-supplied
 * and there is no trusted-proxy layer anywhere in the core — the same reason the
 * session's Secure flag is never derived from the request scheme. Behind a real
 * proxy this collapses every client to one address, which throttles more
 * aggressively rather than less: the safe direction to be wrong in.
 */
final readonly class ClientContext
{
    public function __construct(
        public ?string $ipAddress = null,
        public ?string $userAgent = null,
    ) {}

    public static function fromRequest(ServerRequestInterface $request): self
    {
        $server = $request->getServerParams();
        $address = $server['REMOTE_ADDR'] ?? null;
        $agent = $request->getHeaderLine('User-Agent');

        return new self(
            is_string($address) && $address !== '' ? $address : null,
            $agent === '' ? null : mb_substr($agent, 0, 255),
        );
    }
}
