<?php

declare(strict_types=1);

namespace Liminal\Tests\Integration\Support;

use Doctrine\DBAL\Connection;
use Liminal\Kernel;
use Liminal\Lib\System\Console\InstallCommand;
use Liminal\Module\Authentication\Administration\UserAdministration;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A browser made of assertions, for tests that drive the REAL application
 * root over HTTP: install, harvested cookies and CSRF tokens, form posts.
 *
 * Shared by the journey suites so each can stay about its own story instead
 * of re-implementing cookie parsing. Users are seeded with a deliberately
 * cheap bcrypt hash (cost 4): fast, and it keeps the needsRehash write-back
 * observable where a journey wants to assert it.
 */
trait BrowserJourney
{
    private const string ROOT = __DIR__ . '/../../..';
    private const string COOKIE = 'liminal';

    private Connection $dbal;

    private string $seededHash;

    private ?string $previousDsn = null;

    private function bootstrapInstance(string $dsn): void
    {
        $this->dbal = $this->connection();

        $this->dropAllTables($this->dbal);

        $this->previousDsn = \Liminal\Support\Env::nullableString('LIMINAL_DSN');
        putenv('LIMINAL_DSN=' . $dsn);

        // The real bootstrap path: migrate, seed MAIN (id 1), and install +
        // enable every declared module for it.
        $install = new Kernel(self::ROOT)->container()->get(InstallCommand::class);
        self::assertInstanceOf(InstallCommand::class, $install);
        self::assertSame(Command::SUCCESS, new CommandTester($install)->execute([]));
    }

    private function restoreDsn(): void
    {
        putenv($this->previousDsn === null ? 'LIMINAL_DSN' : 'LIMINAL_DSN=' . $this->previousDsn);
    }

    /**
     * Ada holds the admin role (whatever permissions the caller listed), Bob
     * holds a permissionless member role — both in company 1 only.
     *
     * @param list<string> $adminPermissions
     */
    private function seedAdaAndBob(array $adminPermissions): void
    {
        $this->seededHash = password_hash('correct-horse', PASSWORD_BCRYPT, ['cost' => 4]);

        $users = new UserAdministration(fn(): Connection => $this->dbal);
        $ada = $users->createUser('ada@liminal.test', $this->seededHash, 'Ada Admin');
        $bob = $users->createUser('bob@liminal.test', $this->seededHash, 'Bob Basic');
        $admin = $users->ensureRole('admin', 'Administrator', $adminPermissions);
        $member = $users->ensureRole('member', 'Member', []);
        $users->grant($ada, 1, $admin['id']);
        $users->grant($bob, 1, $member['id']);
    }

    /**
     * A second company the way company creation builds it: row plus module
     * enablement — without the latter, every authentication page would 404
     * the moment anyone switches there.
     */
    private function seedSecondCompany(?string $granting): void
    {
        $this->dbal->insert('core_company', [
            'id' => 2,
            'code' => 'ACME',
            'name' => 'Acme Corp',
            'created_at' => '2026-07-30 00:00:00',
            'updated_at' => '2026-07-30 00:00:00',
        ]);

        $this->dbal->executeStatement(
            'INSERT INTO core_module_company (module_id, company_id, enabled)
             SELECT id, 2, 1 FROM core_module',
        );

        if ($granting === null) {
            return;
        }

        $this->dbal->executeStatement(
            'INSERT INTO core_user_company_role (user_id, company_id, role_id)
             SELECT u.id, 2, r.id FROM core_user u
             JOIN core_role r ON r.code = \'admin\'
             WHERE u.email = ?',
            [$granting],
        );
    }

    private function kernel(): Kernel
    {
        return new Kernel(self::ROOT);
    }

    /**
     * GET the sign-in form as a browser would, harvesting the session cookie
     * it organically sets and the CSRF token embedded in the form.
     *
     * @return array{string, string}
     */
    private function openLoginForm(Kernel $kernel): array
    {
        $response = $kernel->handle($this->get('/login'));

        self::assertSame(200, $response->getStatusCode());

        return [$this->cookieValue($response), $this->tokenFrom((string) $response->getBody())];
    }

    /**
     * The full browser flow from form to authenticated cookie.
     */
    private function login(Kernel $kernel, string $identifier, string $password = 'correct-horse'): string
    {
        [$cookie, $token] = $this->openLoginForm($kernel);

        $response = $kernel->handle($this->post(
            '/login',
            ['identifier' => $identifier, 'password' => $password, '_token' => $token],
            $cookie,
        ));

        self::assertSame('/account', $response->getHeaderLine('Location'));

        return $this->cookieValue($response);
    }

    private function tokenFrom(string $html): string
    {
        if (preg_match('/name="_token" value="([^"]+)"/', $html, $matches) !== 1) {
            self::fail('No CSRF token in the page.');
        }

        return $matches[1];
    }

    private function cookieValue(ResponseInterface $response): string
    {
        if (preg_match('/^' . self::COOKIE . '=([^;]*)/', $response->getHeaderLine('Set-Cookie'), $matches) !== 1) {
            self::fail(sprintf('No session cookie in the response; got "%s".', $response->getHeaderLine('Set-Cookie')));
        }

        return $matches[1];
    }

    private function get(string $path, ?string $cookie = null): ServerRequestInterface
    {
        $uri = new Psr17Factory()->createUri($path);
        $request = new Psr17Factory()->createServerRequest('GET', $uri)
            ->withHeader('Accept', 'text/html,application/xhtml+xml')
            ->withQueryParams($this->queryOf($uri->getQuery()));

        return $cookie === null ? $request : $request->withCookieParams([self::COOKIE => $cookie]);
    }

    /**
     * @param array<string, string|list<string>> $body checkbox groups post lists
     */
    private function post(string $path, array $body, ?string $cookie = null): ServerRequestInterface
    {
        $request = new Psr17Factory()->createServerRequest('POST', $path)
            ->withHeader('Accept', 'text/html,application/xhtml+xml')
            ->withParsedBody($body);

        return $cookie === null ? $request : $request->withCookieParams([self::COOKIE => $cookie]);
    }

    /**
     * Nyholm parses nothing for us: query params, like cookies, are fed by hand.
     *
     * @return array<string, string>
     */
    private function queryOf(string $query): array
    {
        parse_str($query, $parsed);

        $params = [];

        foreach ($parsed as $key => $value) {
            if (is_string($value)) {
                $params[(string) $key] = $value;
            }
        }

        return $params;
    }
}
