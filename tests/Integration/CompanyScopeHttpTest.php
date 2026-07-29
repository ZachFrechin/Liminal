<?php

declare(strict_types=1);

namespace Liminal\Tests\Integration;

use Doctrine\DBAL\Connection;
use Liminal\Kernel;
use Liminal\Lib\Database\Migration\MigrationRunner;
use Liminal\Support\Env;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The phase-1 promise, closed end to end.
 *
 * The database lib fenced entities per company from day one, but nothing
 * pointed the scope at a real actor. Here two users log in over real HTTP and
 * read the same endpoint: each sees only their own company's rows, through the
 * whole stack — session, authentication, company switch, SQL filter. The
 * cookie is the only thing carried between requests.
 */
#[CoversNothing]
final class CompanyScopeHttpTest extends IntegrationTestCase
{
    private const ROOT = __DIR__ . '/Fixtures/security-root';
    private const COOKIE = 'liminal';
    private const COMPANY_A = 1;
    private const COMPANY_B = 2;

    private Connection $dbal;

    private ?string $previousDsn = null;

    protected function setUp(): void
    {
        $dsn = Env::nullableString('LIMINAL_TEST_DSN');

        if ($dsn === null) {
            self::markTestSkipped('LIMINAL_TEST_DSN is not set; skipping company scope HTTP test.');
        }

        $this->dbal = $this->connection();

        $tables = ['test_widget', 'test_gadget', 'core_session', 'core_setting', 'core_module_company', 'core_module', 'core_company', 'core_migration_version'];

        foreach ($tables as $table) {
            $this->dbal->executeStatement(sprintf('DROP TABLE IF EXISTS %s', $table));
        }

        $this->previousDsn = Env::nullableString('LIMINAL_DSN');
        putenv('LIMINAL_DSN=' . $dsn);

        $runner = new Kernel(self::ROOT)->container()->get(MigrationRunner::class);
        self::assertInstanceOf(MigrationRunner::class, $runner);
        $runner->migrateToLatest();

        foreach ([['MAIN', self::COMPANY_A], ['SECOND', self::COMPANY_B]] as [$code, $id]) {
            $this->dbal->insert('core_company', [
                'id' => $id,
                'code' => $code,
                'name' => $code,
                'created_at' => '2026-07-29 00:00:00',
                'updated_at' => '2026-07-29 00:00:00',
            ]);
        }

        $this->createWidgetTable();

        // Seeded through raw SQL: the ORM would refuse to write company B's
        // rows from company A's scope, which is the guarantee under test.
        foreach ([['a-one', self::COMPANY_A], ['a-two', self::COMPANY_A], ['b-one', self::COMPANY_B]] as [$label, $company]) {
            $this->dbal->insert('test_widget', ['label' => $label, 'company_id' => $company]);
        }
    }

    protected function tearDown(): void
    {
        putenv($this->previousDsn === null ? 'LIMINAL_DSN' : 'LIMINAL_DSN=' . $this->previousDsn);
    }

    /**
     * The isolation property, over real HTTP: bob reaches company 2 only, so
     * company 1's rows are invisible to him — not filtered in the handler, not
     * hidden by the view, absent from the SQL. Two different users hitting the
     * same URL in the same process get different data, and the request scope
     * does not leak between them.
     */
    public function testAUserNeverSeesAnotherCompanysRowsThroughRealHttp(): void
    {
        $kernel = $this->kernel();

        $bob = $this->login($kernel, 'bob', 'bob-secret');
        $bobWidgets = $kernel->handle($this->get('/widgets', $bob));

        self::assertSame(200, $bobWidgets->getStatusCode());
        self::assertSame(['b-one'], $this->widgets($bobWidgets));

        // Alice logs in on the same kernel; bob's next request is unaffected.
        $alice = $this->login($kernel, 'alice', 'alice-secret');

        self::assertSame(['b-one'], $this->widgets($kernel->handle($this->get('/widgets', $bob))));
        self::assertNotSame(['b-one'], $this->widgets($kernel->handle($this->get('/widgets', $alice))));
    }

    /**
     * The reading scope is the ACCESSIBLE set, not the current company alone —
     * the filter emits "company_id IN (...)" — so an actor entitled to two
     * companies reads across both (the phase-1 CompanyScopeTest documents the
     * same rule at the ORM level). The current company is what new rows are
     * stamped with, not a read blinder.
     */
    public function testAnActorEntitledToTwoCompaniesReadsAcrossBoth(): void
    {
        $kernel = $this->kernel();
        $alice = $this->login($kernel, 'alice', 'alice-secret');

        self::assertSame(['a-one', 'a-two', 'b-one'], $this->widgets($kernel->handle($this->get('/widgets', $alice))));
    }

    public function testTheScopeLandsOnTheUsersFirstAccessibleCompany(): void
    {
        $kernel = $this->kernel();

        $bob = $this->login($kernel, 'bob', 'bob-secret');
        $me = $kernel->handle($this->get('/me', $bob));

        self::assertStringContainsString('"company":' . self::COMPANY_B, (string) $me->getBody());
    }

    /**
     * The stored preference is untrusted input: a company the user cannot
     * reach must fall back to an accessible one, never widen the scope.
     */
    public function testAPoisonedSessionCompanyIdFallsBackToAnAccessibleOne(): void
    {
        $kernel = $this->kernel();
        $bob = $this->login($kernel, 'bob', 'bob-secret');

        // Bob may only reach company 2; forge company 1 into his session row.
        $this->dbal->executeStatement(
            'UPDATE core_session SET company_id = ? WHERE id = ?',
            [self::COMPANY_A, hash('sha256', $bob)],
        );

        $me = $kernel->handle($this->get('/me', $bob));

        self::assertStringContainsString('"company":' . self::COMPANY_B, (string) $me->getBody());
        self::assertSame(['b-one'], $this->widgets($kernel->handle($this->get('/widgets', $bob))));
    }

    private function kernel(): Kernel
    {
        return new Kernel(self::ROOT);
    }

    /**
     * @return list<string>
     */
    private function widgets(ResponseInterface $response): array
    {
        $body = json_decode((string) $response->getBody(), true);

        self::assertIsArray($body);
        self::assertIsArray($body['widgets'] ?? null);

        $labels = [];

        foreach ($body['widgets'] as $label) {
            self::assertIsString($label);
            $labels[] = $label;
        }

        return $labels;
    }

    private function login(Kernel $kernel, string $identifier, string $password): string
    {
        $tokenResponse = $kernel->handle($this->get('/token'));
        $body = json_decode((string) $tokenResponse->getBody(), true);
        $token = is_array($body) && is_string($body['token'] ?? null) ? $body['token'] : '';
        $anonymous = $this->cookieValue($tokenResponse);

        $login = $kernel->handle(
            new Psr17Factory()->createServerRequest('POST', '/login')
                ->withParsedBody(['identifier' => $identifier, 'password' => $password, '_token' => $token])
                ->withCookieParams([self::COOKIE => $anonymous]),
        );

        self::assertSame(200, $login->getStatusCode(), (string) $login->getBody());

        return $this->cookieValue($login);
    }

    private function get(string $path, ?string $cookie = null): ServerRequestInterface
    {
        $request = new Psr17Factory()->createServerRequest('GET', $path);

        return $cookie === null ? $request : $request->withCookieParams([self::COOKIE => $cookie]);
    }

    private function cookieValue(ResponseInterface $response): string
    {
        $header = $response->getHeaderLine('Set-Cookie');

        if (preg_match('/^' . self::COOKIE . '=([^;]*)/', $header, $matches) !== 1) {
            self::fail(sprintf('No session cookie in the response; got "%s".', $header));
        }

        return $matches[1];
    }

    private function createWidgetTable(): void
    {
        $this->dbal->executeStatement(
            'CREATE TABLE test_widget (
                id INT AUTO_INCREMENT NOT NULL,
                company_id INT NOT NULL,
                label VARCHAR(128) NOT NULL,
                INDEX idx_test_widget_scope (company_id, id),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB',
        );
    }
}
