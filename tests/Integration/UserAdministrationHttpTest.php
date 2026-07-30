<?php

declare(strict_types=1);

namespace Liminal\Tests\Integration;

use Liminal\Support\Env;
use Liminal\Tests\Integration\Support\BrowserJourney;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * The user administration screens through a browser: create with the one-time
 * password, edit, deactivate, delete — and every self-guard. The strongest
 * assertion in here signs in with a password harvested from the created page,
 * proving the generate–hash–verify loop end to end.
 */
#[CoversNothing]
final class UserAdministrationHttpTest extends IntegrationTestCase
{
    use BrowserJourney;

    protected function setUp(): void
    {
        $dsn = Env::nullableString('LIMINAL_TEST_DSN');

        if ($dsn === null) {
            self::markTestSkipped('LIMINAL_TEST_DSN is not set; skipping user administration http test.');
        }

        $this->bootstrapInstance($dsn);
        $this->seedAdaAndBob(['authentication.user.manage']);
    }

    protected function tearDown(): void
    {
        $this->restoreDsn();
    }

    public function testCreatingAUserRevealsThePasswordOnceAndItActuallySignsIn(): void
    {
        $kernel = $this->kernel();
        $ada = $this->login($kernel, 'ada@liminal.test');

        $form = $kernel->handle($this->get('/users/create', $ada));
        self::assertSame(200, $form->getStatusCode());

        $created = $kernel->handle($this->post(
            '/users/create',
            [
                'email' => 'carol@liminal.test',
                'display_name' => 'Carol',
                '_token' => $this->tokenFrom((string) $form->getBody()),
            ],
            $ada,
        ));

        // Success renders directly — the secret's only clear-text copy.
        self::assertSame(200, $created->getStatusCode());
        self::assertSame('no-store', $created->getHeaderLine('Cache-Control'));

        $html = (string) $created->getBody();

        self::assertStringContainsString('carol@liminal.test', $html);
        self::assertStringContainsString('shown only this once', $html);
        if (preg_match('/<code class="one-time-password">([^<]+)<\/code>/', $html, $matches) !== 1) {
            self::fail('No one-time password on the page.');
        }
        $password = $matches[1];

        // Nothing in the database holds the secret in the clear.
        self::assertFalse($this->dbal->fetchOne(
            'SELECT id FROM core_user WHERE password_hash = ?',
            [$password],
        ));

        // A grant somewhere makes Carol signable-in; the harvested password works.
        $this->dbal->executeStatement(
            "INSERT INTO core_user_company_role (user_id, company_id, role_id)
             SELECT u.id, 1, r.id FROM core_user u JOIN core_role r ON r.code = 'member'
             WHERE u.email = 'carol@liminal.test'",
        );

        $carol = $this->login($kernel, 'carol@liminal.test', $password);
        $account = (string) $kernel->handle($this->get('/account', $carol))->getBody();

        self::assertStringContainsString('Signed in as Carol (carol@liminal.test).', $account);
    }

    public function testADuplicateEmailFlashesBackToTheFormAndCreatesNothing(): void
    {
        $kernel = $this->kernel();
        $ada = $this->login($kernel, 'ada@liminal.test');

        $form = $kernel->handle($this->get('/users/create', $ada));
        $response = $kernel->handle($this->post(
            '/users/create',
            ['email' => 'Bob@Liminal.test', '_token' => $this->tokenFrom((string) $form->getBody())],
            $ada,
        ));

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/users/create', $response->getHeaderLine('Location'));

        $reloaded = (string) $kernel->handle($this->get('/users/create', $ada))->getBody();

        self::assertStringContainsString('A user with that email already exists.', $reloaded);
        self::assertEquals(2, $this->dbal->fetchOne('SELECT COUNT(*) FROM core_user'));
    }

    public function testTheListLinksEachUserAndBadgesTheOneNobodyGranted(): void
    {
        $kernel = $this->kernel();
        $ada = $this->login($kernel, 'ada@liminal.test');

        $this->dbal->insert('core_user', [
            'email' => 'stranded@liminal.test',
            'password_hash' => 'x',
            'display_name' => 'Stranded',
            'is_active' => 1,
            'created_at' => '2026-07-30 00:00:00',
            'updated_at' => '2026-07-30 00:00:00',
        ]);

        $html = (string) $kernel->handle($this->get('/users', $ada))->getBody();

        self::assertStringContainsString('Create a user', $html);
        self::assertMatchesRegularExpression('~href="/users/\d+"~', $html);
        // Exactly one user cannot sign in anywhere — the badge says so.
        self::assertSame(1, substr_count($html, 'no access'));
    }

    public function testRenamingAndDeactivatingAnotherUserEndsTheirLiveSession(): void
    {
        $kernel = $this->kernel();
        $bob = $this->login($kernel, 'bob@liminal.test');
        $ada = $this->login($kernel, 'ada@liminal.test');

        $bobId = $this->idOf('bob@liminal.test');

        $detail = $kernel->handle($this->get('/users/' . $bobId, $ada));
        self::assertSame(200, $detail->getStatusCode());

        $update = $kernel->handle($this->post(
            '/users/' . $bobId,
            [
                'display_name' => 'Robert Basic',
                'active' => '0',
                '_token' => $this->tokenFrom((string) $detail->getBody()),
            ],
            $ada,
        ));

        self::assertSame(302, $update->getStatusCode());

        $after = (string) $kernel->handle($this->get('/users/' . $bobId, $ada))->getBody();
        self::assertStringContainsString('User updated.', $after);
        self::assertStringContainsString('Robert Basic', $after);

        // Deactivation is live: Bob's authenticated session dies on his next
        // request — is_active is filtered on hydration, not just at login.
        self::assertSame(302, $kernel->handle($this->get('/account', $bob))->getStatusCode());
    }

    public function testSelfDeactivationIsRefused(): void
    {
        $kernel = $this->kernel();
        $ada = $this->login($kernel, 'ada@liminal.test');

        $adaId = $this->idOf('ada@liminal.test');

        $detail = $kernel->handle($this->get('/users/' . $adaId, $ada));
        $response = $kernel->handle($this->post(
            '/users/' . $adaId,
            [
                'display_name' => 'Ada Admin',
                'active' => '0',
                '_token' => $this->tokenFrom((string) $detail->getBody()),
            ],
            $ada,
        ));

        self::assertSame(302, $response->getStatusCode());

        $after = (string) $kernel->handle($this->get('/users/' . $adaId, $ada))->getBody();

        self::assertStringContainsString('You cannot deactivate your own account.', $after);
        self::assertEquals(1, $this->dbal->fetchOne(
            "SELECT is_active FROM core_user WHERE email = 'ada@liminal.test'",
        ));
        // Ada's own session survived her refused attempt.
        self::assertSame(200, $kernel->handle($this->get('/account', $ada))->getStatusCode());
    }

    public function testDeletingAUserRemovesThemAndSelfDeletionIsRefused(): void
    {
        $kernel = $this->kernel();
        $ada = $this->login($kernel, 'ada@liminal.test');

        $bobId = $this->idOf('bob@liminal.test');
        $adaId = $this->idOf('ada@liminal.test');

        // Self-deletion: refused, flashed, nothing changed.
        $detail = $kernel->handle($this->get('/users/' . $adaId, $ada));
        $token = $this->tokenFrom((string) $detail->getBody());

        $refused = $kernel->handle($this->post('/users/' . $adaId . '/delete', ['_token' => $token], $ada));
        self::assertSame(302, $refused->getStatusCode());
        self::assertEquals(2, $this->dbal->fetchOne('SELECT COUNT(*) FROM core_user'));

        // Another user: gone, with the grants cascading away.
        $deleted = $kernel->handle($this->post('/users/' . $bobId . '/delete', ['_token' => $token], $ada));
        self::assertSame(302, $deleted->getStatusCode());
        self::assertSame('/users', $deleted->getHeaderLine('Location'));

        self::assertFalse($this->dbal->fetchOne("SELECT id FROM core_user WHERE email = 'bob@liminal.test'"));
        self::assertEquals(0, $this->dbal->fetchOne(
            'SELECT COUNT(*) FROM core_user_company_role ucr WHERE ucr.user_id = ?',
            [$bobId],
        ));

        $list = (string) $kernel->handle($this->get('/users', $ada))->getBody();
        self::assertStringContainsString('User deleted.', $list);
        self::assertStringNotContainsString('bob@liminal.test', $list);
    }

    private function idOf(string $email): int
    {
        $id = $this->dbal->fetchOne('SELECT id FROM core_user WHERE email = ?', [$email]);
        self::assertIsNumeric($id);

        return (int) $id;
    }

    public function testTheScreensAreForbiddenWithoutThePermissionAndUnknownIdsAre404(): void
    {
        $kernel = $this->kernel();

        // Bob's member role holds no permissions at all.
        $bob = $this->login($kernel, 'bob@liminal.test');
        self::assertSame(403, $kernel->handle($this->get('/users/create', $bob))->getStatusCode());
        self::assertSame(403, $kernel->handle($this->get('/users/1', $bob))->getStatusCode());

        $ada = $this->login($kernel, 'ada@liminal.test');
        self::assertSame(404, $kernel->handle($this->get('/users/999', $ada))->getStatusCode());
        // A non-numeric id never reaches the handler: {id:\d+} makes the
        // router answer, indistinguishable from any other unknown path.
        self::assertSame(404, $kernel->handle($this->get('/users/nope', $ada))->getStatusCode());
    }
}
