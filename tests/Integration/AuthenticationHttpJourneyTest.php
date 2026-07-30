<?php

declare(strict_types=1);

namespace Liminal\Tests\Integration;

use Doctrine\DBAL\Connection;
use Liminal\Kernel;
use Liminal\Lib\System\Console\InstallCommand;
use Liminal\Module\Authentication\Administration\UserAdministration;
use Liminal\Support\Env;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The authentication module through a browser's eyes, on the REAL application
 * root: install, sign in, get around, get refused, sign out. Every claim the
 * phase made about the composed stack — public routes before enablement, flash
 * on failure, rotation on success, RBAC on the users page, throttling, audit —
 * is asserted here over actual HTTP round trips.
 *
 * Users are seeded with a deliberately cheap bcrypt hash (cost 4): it keeps the
 * suite fast AND makes the needsRehash write-back observable, because the house
 * cost is higher and a successful login must upgrade the stored hash.
 */
#[CoversNothing]
final class AuthenticationHttpJourneyTest extends IntegrationTestCase
{
    private const ROOT = __DIR__ . '/../..';
    private const COOKIE = 'liminal';

    /** From config/security.php login_throttle.max_failures (env default). */
    private const MAX_FAILURES = 10;

    private Connection $dbal;

    private string $seededHash;

    private ?string $previousDsn = null;

    protected function setUp(): void
    {
        $dsn = Env::nullableString('LIMINAL_TEST_DSN');

        if ($dsn === null) {
            self::markTestSkipped('LIMINAL_TEST_DSN is not set; skipping authentication journey test.');
        }

        $this->dbal = $this->connection();

        $this->dropAllTables($this->dbal);

        $this->previousDsn = Env::nullableString('LIMINAL_DSN');
        putenv('LIMINAL_DSN=' . $dsn);

        // The real bootstrap path: migrate, seed MAIN (id 1), and install +
        // enable every declared module for it — authentication included.
        $install = new Kernel(self::ROOT)->container()->get(InstallCommand::class);
        self::assertInstanceOf(InstallCommand::class, $install);
        self::assertSame(Command::SUCCESS, new CommandTester($install)->execute([]));

        $this->seededHash = password_hash('correct-horse', PASSWORD_BCRYPT, ['cost' => 4]);

        $users = new UserAdministration(fn(): Connection => $this->dbal);
        $ada = $users->createUser('ada@liminal.test', $this->seededHash, 'Ada Admin');
        $bob = $users->createUser('bob@liminal.test', $this->seededHash, 'Bob Basic');
        $admin = $users->ensureRole('admin', 'Administrator', ['authentication.user.manage']);
        $member = $users->ensureRole('member', 'Member', []);
        $users->grant($ada, 1, $admin['id']);
        $users->grant($bob, 1, $member['id']);
    }

    protected function tearDown(): void
    {
        putenv($this->previousDsn === null ? 'LIMINAL_DSN' : 'LIMINAL_DSN=' . $this->previousDsn);
    }

    /**
     * The chicken-and-egg property with teeth: sign-in must answer while NO
     * module is enabled for anyone, or `module:disable` becomes a lockout with
     * no web recourse.
     */
    public function testTheLoginPageAnswersWhileNoModuleIsEnabledForAnyCompany(): void
    {
        $this->dbal->executeStatement('DELETE FROM core_module_company');

        $response = $this->kernel()->handle($this->get('/login'));

        self::assertSame(200, $response->getStatusCode());

        $html = (string) $response->getBody();

        self::assertStringContainsString('action="/login"', $html);
        self::assertStringContainsString('name="_token"', $html);
    }

    public function testAWrongPasswordFlashesAndLeavesTheSessionAnonymous(): void
    {
        $kernel = $this->kernel();
        [$cookie, $token] = $this->openLoginForm($kernel);

        $response = $kernel->handle($this->post(
            '/login',
            ['identifier' => 'ada@liminal.test', 'password' => 'wrong', '_token' => $token],
            $cookie,
        ));

        // Failure responds — 302 back to the form, never a throw.
        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/login', $response->getHeaderLine('Location'));
        self::assertNull($this->dbal->fetchOne('SELECT user_id FROM core_session'));

        // The redirected GET renders the flash, and the untouched CSRF token
        // means the re-rendered form still works.
        $form = (string) $kernel->handle($this->get('/login', $cookie))->getBody();

        self::assertStringContainsString('Those credentials were not accepted.', $form);
        self::assertStringContainsString($token, $form);

        self::assertSame(
            ['login.refused'],
            $this->auditTrail('ada@liminal.test'),
        );
    }

    /**
     * An unknown email and a wrong password must be indistinguishable from the
     * outside — same status, same destination, same words — or the login form
     * is an account-enumeration oracle.
     */
    public function testAnUnknownEmailIsIndistinguishableFromAWrongPassword(): void
    {
        $kernel = $this->kernel();
        $surface = [];

        foreach (['nobody@liminal.test', 'bob@liminal.test'] as $identifier) {
            [$cookie, $token] = $this->openLoginForm($kernel);

            $response = $kernel->handle($this->post(
                '/login',
                ['identifier' => $identifier, 'password' => 'wrong', '_token' => $token],
                $cookie,
            ));

            $flash = (string) $kernel->handle($this->get('/login', $cookie))->getBody();
            preg_match('/<p class="flash flash-error"[^>]*>([^<]*)<\/p>/', $flash, $matches);

            $surface[$identifier] = [
                $response->getStatusCode(),
                $response->getHeaderLine('Location'),
                $matches[1] ?? '(no flash)',
            ];
        }

        self::assertSame($surface['nobody@liminal.test'], $surface['bob@liminal.test']);
        // Internally the trail differs — the refusal of a real user carries
        // their id — and that asymmetry must stay server-side.
        self::assertSame(['login.refused'], $this->auditTrail('nobody@liminal.test'));
        self::assertSame(['login.refused'], $this->auditTrail('bob@liminal.test'));
    }

    public function testTheRightPasswordRotatesTheSessionGreetsAndUpgradesTheHash(): void
    {
        $kernel = $this->kernel();
        [$anonymous, $token] = $this->openLoginForm($kernel);

        $response = $kernel->handle($this->post(
            '/login',
            ['identifier' => 'Ada@Liminal.test', 'password' => 'correct-horse', '_token' => $token],
            $anonymous,
        ));

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/account', $response->getHeaderLine('Location'));

        // Success regenerated the session: a new cookie, and the old id dead.
        $authenticated = $this->cookieValue($response);
        self::assertNotSame($anonymous, $authenticated);
        self::assertSame(302, $kernel->handle($this->get('/account', $anonymous))->getStatusCode());

        $account = $kernel->handle($this->get('/account', $authenticated));
        self::assertSame(200, $account->getStatusCode());

        $html = (string) $account->getBody();

        self::assertStringContainsString('Welcome back.', $html);
        self::assertStringContainsString('Signed in as Ada Admin (ada@liminal.test).', $html);
        self::assertStringContainsString('Main company (MAIN) — current', $html);

        // The cost-4 hash was rewritten at the house cost by the write-back —
        // and still verifies.
        $stored = $this->dbal->fetchOne('SELECT password_hash FROM core_user WHERE email = ?', ['ada@liminal.test']);
        self::assertIsString($stored);
        self::assertNotSame($this->seededHash, $stored);
        self::assertTrue(password_verify('correct-horse', $stored));

        self::assertSame(['login.granted'], $this->auditTrail('ada@liminal.test'));
    }

    public function testAProtectedPageRedirectsThroughLoginAndBackToWhereYouWereGoing(): void
    {
        $kernel = $this->kernel();

        // Anonymous browser hits a protected page: 401 dressed as a redirect
        // that remembers the destination.
        $refused = $kernel->handle($this->get('/account'));
        self::assertSame(302, $refused->getStatusCode());
        self::assertSame('/login?redirect=%2Faccount', $refused->getHeaderLine('Location'));

        // The form carries the validated target through the POST…
        $page = $kernel->handle($this->get('/login?redirect=%2Faccount'));
        $html = (string) $page->getBody();
        self::assertStringContainsString('name="redirect" value="/account"', $html);

        [$cookie, $token] = $this->openLoginForm($kernel);
        $login = $kernel->handle($this->post(
            '/login',
            [
                'identifier' => 'ada@liminal.test',
                'password' => 'correct-horse',
                '_token' => $token,
                'redirect' => '/account',
            ],
            $cookie,
        ));

        // …and success lands where the browser was going in the first place.
        self::assertSame(302, $login->getStatusCode());
        self::assertSame('/account', $login->getHeaderLine('Location'));
    }

    public function testAHostileRedirectTargetFallsBackToTheAccountPage(): void
    {
        $kernel = $this->kernel();
        [$cookie, $token] = $this->openLoginForm($kernel);

        $login = $kernel->handle($this->post(
            '/login',
            [
                'identifier' => 'ada@liminal.test',
                'password' => 'correct-horse',
                '_token' => $token,
                'redirect' => 'https://evil.example/phish',
            ],
            $cookie,
        ));

        self::assertSame(302, $login->getStatusCode());
        self::assertSame('/account', $login->getHeaderLine('Location'));
    }

    public function testTheUsersPageIsForbiddenWithoutThePermissionAndListsEveryoneWithIt(): void
    {
        $kernel = $this->kernel();

        // Bob holds a role with no permissions: the page refuses him.
        $bob = $this->login($kernel, 'bob@liminal.test');
        $refused = $kernel->handle($this->get('/users', $bob));

        self::assertSame(403, $refused->getStatusCode());
        self::assertStringStartsWith('text/html', $refused->getHeaderLine('Content-Type'));

        // Ada holds authentication.user.manage: the page answers, and shows
        // everyone with their roles in this company.
        $ada = $this->login($kernel, 'ada@liminal.test');
        $granted = $kernel->handle($this->get('/users', $ada));

        self::assertSame(200, $granted->getStatusCode());

        $html = (string) $granted->getBody();

        self::assertStringContainsString('ada@liminal.test', $html);
        self::assertStringContainsString('bob@liminal.test', $html);
        self::assertStringContainsString('member', $html);
    }

    public function testTheMenuOffersTheUsersPageOnlyToThoseWhoMayManageUsers(): void
    {
        $kernel = $this->kernel();

        $ada = (string) $kernel->handle($this->get('/account', $this->login($kernel, 'ada@liminal.test')))->getBody();
        $bob = (string) $kernel->handle($this->get('/account', $this->login($kernel, 'bob@liminal.test')))->getBody();

        self::assertStringContainsString('href="/users"', $ada);
        self::assertStringNotContainsString('href="/users"', $bob);

        // The account entry is permissionless: both see it.
        self::assertStringContainsString('href="/account"', $ada);
        self::assertStringContainsString('href="/account"', $bob);

        // Labels reach the screen translated, not as raw catalogue keys.
        self::assertStringContainsString('>Users</a>', $ada);
        self::assertStringContainsString('>Account</a>', $bob);
        self::assertStringNotContainsString('authentication.menu.', $ada);
    }

    /**
     * Sign-out must survive the module being disabled for your company: /logout
     * is public precisely so an administrative decision can never trap anyone
     * in a session they no longer want.
     */
    public function testLogoutStillWorksWhileTheModuleIsDisabled(): void
    {
        $kernel = $this->kernel();
        $cookie = $this->login($kernel, 'ada@liminal.test');

        // The sign-out form (and its token) came from the account page…
        $token = $this->tokenFrom((string) $kernel->handle($this->get('/account', $cookie))->getBody());

        // …then the module is switched off under the signed-in user.
        $this->dbal->executeStatement('UPDATE core_module_company SET enabled = 0');

        // Module pages are gone — indistinguishable from absence — but the
        // sign-out POST still answers.
        self::assertSame(404, $kernel->handle($this->get('/account', $cookie))->getStatusCode());

        $logout = $kernel->handle($this->post('/logout', ['_token' => $token], $cookie));

        self::assertSame(302, $logout->getStatusCode());
        self::assertSame('/login', $logout->getHeaderLine('Location'));

        // The server-side session is dead: the old cookie is anonymous again.
        self::assertSame(302, $kernel->handle($this->get('/account', $cookie))->getStatusCode());

        // The logout event is recorded under the user id — there is no
        // submitted identifier at logout time, and the audit must not invent one.
        self::assertEquals(1, $this->dbal->fetchOne(
            "SELECT COUNT(*) FROM core_auth_event e
             JOIN core_user u ON u.id = e.user_id
             WHERE u.email = 'ada@liminal.test' AND e.event = 'logout'",
        ));
    }

    public function testTheAccountPageIsNeverCached(): void
    {
        $kernel = $this->kernel();

        $response = $kernel->handle($this->get('/account', $this->login($kernel, 'ada@liminal.test')));

        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
    }

    /**
     * The switcher writes the preference; the middleware applies it on the
     * NEXT request — which is exactly what the second GET proves.
     */
    public function testSwitchingCompaniesMovesTheWorkingScopeOnTheNextRequest(): void
    {
        $this->seedSecondCompany(granting: 'ada@liminal.test');

        $kernel = $this->kernel();
        $cookie = $this->login($kernel, 'ada@liminal.test');

        $account = (string) $kernel->handle($this->get('/account', $cookie))->getBody();

        // Both companies listed, MAIN current, ACME offered as a button.
        self::assertStringContainsString('Main company (MAIN) — current', $account);
        self::assertStringContainsString('Acme Corp (ACME)', $account);
        self::assertStringContainsString('Work in this company', $account);

        $switch = $kernel->handle($this->post(
            '/switch-company',
            ['company' => '2', '_token' => $this->tokenFrom($account)],
            $cookie,
        ));

        self::assertSame(302, $switch->getStatusCode());
        self::assertSame('/account', $switch->getHeaderLine('Location'));

        $after = (string) $kernel->handle($this->get('/account', $cookie))->getBody();

        self::assertStringContainsString('Working company switched.', $after);
        self::assertStringContainsString('Acme Corp (ACME) — current', $after);
        self::assertStringNotContainsString('Main company (MAIN) — current', $after);
    }

    /**
     * An explicit click on a company that is not yours earns an explicit
     * refusal — and the scope does not move.
     */
    public function testSwitchingToSomeoneElsesCompanyIsRefused(): void
    {
        $this->seedSecondCompany(granting: null);

        $kernel = $this->kernel();
        $cookie = $this->login($kernel, 'ada@liminal.test');

        $token = $this->tokenFrom((string) $kernel->handle($this->get('/account', $cookie))->getBody());

        $switch = $kernel->handle($this->post(
            '/switch-company',
            ['company' => '2', '_token' => $token],
            $cookie,
        ));

        self::assertSame(302, $switch->getStatusCode());

        $after = (string) $kernel->handle($this->get('/account', $cookie))->getBody();

        self::assertStringContainsString('That company is not yours to work in.', $after);
        self::assertStringContainsString('Main company (MAIN) — current', $after);
    }

    /**
     * After max_failures wrong passwords the RIGHT password is refused too,
     * with Retry-After as the honest signal — proof the throttle is consulted
     * before any verification buys CPU.
     */
    public function testRepeatedFailuresThrottleEvenTheRightPassword(): void
    {
        $kernel = $this->kernel();
        [$cookie, $token] = $this->openLoginForm($kernel);

        for ($attempt = 0; $attempt < self::MAX_FAILURES; ++$attempt) {
            $kernel->handle($this->post(
                '/login',
                ['identifier' => 'ada@liminal.test', 'password' => 'wrong', '_token' => $token],
                $cookie,
            ));
        }

        $throttled = $kernel->handle($this->post(
            '/login',
            ['identifier' => 'ada@liminal.test', 'password' => 'correct-horse', '_token' => $token],
            $cookie,
        ));

        self::assertSame(302, $throttled->getStatusCode());
        self::assertGreaterThan(0, (int) $throttled->getHeaderLine('Retry-After'));
        // No row at all: the right password bought no session while locked out.
        self::assertFalse($this->dbal->fetchOne('SELECT id FROM core_session WHERE user_id IS NOT NULL'));

        $flash = (string) $kernel->handle($this->get('/login', $cookie))->getBody();
        self::assertStringContainsString('Too many attempts. Please wait before trying again.', $flash);

        $trail = $this->auditTrail('ada@liminal.test');
        self::assertSame(array_fill(0, self::MAX_FAILURES, 'login.refused'), array_slice($trail, 0, self::MAX_FAILURES));
        self::assertSame('login.throttled', $trail[self::MAX_FAILURES] ?? null);
    }

    private function kernel(): Kernel
    {
        return new Kernel(self::ROOT);
    }

    /**
     * A second company the way company creation will build it: row plus module
     * enablement — without the latter, every authentication page would 404 the
     * moment anyone switches there.
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
            "INSERT INTO core_module_company (module_id, company_id, enabled)
             SELECT id, 2, 1 FROM core_module WHERE name = 'authentication'",
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
    private function login(Kernel $kernel, string $identifier): string
    {
        [$cookie, $token] = $this->openLoginForm($kernel);

        $response = $kernel->handle($this->post(
            '/login',
            ['identifier' => $identifier, 'password' => 'correct-horse', '_token' => $token],
            $cookie,
        ));

        self::assertSame('/account', $response->getHeaderLine('Location'));

        return $this->cookieValue($response);
    }

    /**
     * @return list<string> event codes recorded for one identifier, oldest first
     */
    private function auditTrail(string $identifier): array
    {
        $events = $this->dbal->fetchFirstColumn(
            'SELECT event FROM core_auth_event WHERE identifier = ? ORDER BY id',
            [mb_strtolower($identifier)],
        );

        $trail = [];

        foreach ($events as $event) {
            if (is_string($event)) {
                $trail[] = $event;
            }
        }

        return $trail;
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
     * @param array<string, string> $body
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
