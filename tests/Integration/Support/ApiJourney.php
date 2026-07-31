<?php

declare(strict_types=1);

namespace Liminal\Tests\Integration\Support;

use Liminal\Module\Authentication\Administration\TokenAdministration;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The JSON face of a journey: bearer-authenticated requests and decoded
 * envelopes. Additive by design — a test uses it ALONGSIDE BrowserJourney,
 * which keeps owning the instance bootstrap, the seeding and the database
 * handle this trait borrows.
 */
trait ApiJourney
{
    private function apiGet(string $path, ?string $bearer = null, ?string $company = null): ServerRequestInterface
    {
        $request = new Psr17Factory()->createServerRequest('GET', $path)
            ->withHeader('Accept', 'application/json');

        $uri = $request->getUri();

        if ($uri->getQuery() !== '') {
            $request = $request->withQueryParams($this->queryOf($uri->getQuery()));
        }

        if ($bearer !== null) {
            $request = $request->withHeader('Authorization', 'Bearer ' . $bearer);
        }

        if ($company !== null) {
            $request = $request->withHeader('X-Liminal-Company', $company);
        }

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonFrom(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true);

        if (!is_array($decoded)) {
            self::fail('The response body is not a JSON object: ' . (string) $response->getBody());
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /** Mints a real token for a seeded user and returns the raw value. */
    private function mintToken(string $email): string
    {
        $userId = $this->dbal->fetchOne('SELECT id FROM core_user WHERE email = ?', [$email]);
        self::assertIsNumeric($userId, sprintf('No user with the email "%s" to mint for.', $email));

        return new TokenAdministration(fn() => $this->dbal)->mint((int) $userId, 'test')->raw;
    }
}
