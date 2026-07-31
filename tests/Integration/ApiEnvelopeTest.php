<?php

declare(strict_types=1);

namespace Liminal\Tests\Integration;

use Liminal\Support\Env;
use Liminal\Tests\Integration\Support\ApiJourney;
use Liminal\Tests\Integration\Support\BrowserJourney;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * The two envelopes an API client will ever parse, pinned through the real
 * kernel before any module ships an api route: errors are the kernel's
 * {error: {status, message}} — the JSON-first shape that predates this lib —
 * and a bearer credential authenticates without cookie or CSRF token.
 */
#[CoversNothing]
final class ApiEnvelopeTest extends IntegrationTestCase
{
    use ApiJourney;
    use BrowserJourney;

    protected function setUp(): void
    {
        $dsn = Env::nullableString('LIMINAL_TEST_DSN');

        if ($dsn === null) {
            self::markTestSkipped('LIMINAL_TEST_DSN is not set; skipping api envelope test.');
        }

        $this->bootstrapInstance($dsn);
        $this->seedAdaAndBob([]);
    }

    protected function tearDown(): void
    {
        $this->restoreDsn();
    }

    public function testTheErrorEnvelopeIsTheKernelsAndTheBearerRides(): void
    {
        $kernel = $this->kernel();

        // Anonymous JSON client on a protected route: the kernel envelope.
        $unauthorized = $kernel->handle($this->apiGet('/account'));
        self::assertSame(401, $unauthorized->getStatusCode());
        $body = $this->jsonFrom($unauthorized);
        self::assertSame(['status' => 401, 'message' => 'Authentication required.'], $body['error'] ?? null);

        // An invalid bearer keeps the same envelope, plus the RFC header.
        $invalid = $kernel->handle($this->apiGet('/account', bearer: 'liminal_wrong'));
        self::assertSame(401, $invalid->getStatusCode());
        self::assertSame('Bearer error="invalid_token"', $invalid->getHeaderLine('WWW-Authenticate'));

        // A minted one authenticates — no cookie, no CSRF token involved.
        $account = $kernel->handle($this->apiGet('/account', bearer: $this->mintToken('ada@liminal.test')));
        self::assertSame(200, $account->getStatusCode());
    }
}
