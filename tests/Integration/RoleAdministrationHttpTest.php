<?php

declare(strict_types=1);

namespace Liminal\Tests\Integration;

use Liminal\Support\Env;
use Liminal\Tests\Integration\Support\BrowserJourney;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * The role screens through a browser — including the loop that makes RBAC
 * tangible: edit a role, and its holders' effective permissions change on
 * their very next request.
 */
#[CoversNothing]
final class RoleAdministrationHttpTest extends IntegrationTestCase
{
    use BrowserJourney;

    protected function setUp(): void
    {
        $dsn = Env::nullableString('LIMINAL_TEST_DSN');

        if ($dsn === null) {
            self::markTestSkipped('LIMINAL_TEST_DSN is not set; skipping role administration http test.');
        }

        $this->bootstrapInstance($dsn);
        $this->seedAdaAndBob(['authentication.user.manage', 'authentication.role.manage']);
    }

    protected function tearDown(): void
    {
        $this->restoreDsn();
    }

    /**
     * The raw-catalogue-key guard the 5a journey cannot give: ada there lacks
     * the new permissions, so a missing menu key would sail through. Here she
     * holds them all, every item renders, and no key leaks.
     */
    public function testTheMenuAndListRenderTranslatedForAFullyPermittedUser(): void
    {
        $kernel = $this->kernel();
        $ada = $this->login($kernel, 'ada@liminal.test');

        $account = (string) $kernel->handle($this->get('/account', $ada))->getBody();

        self::assertStringContainsString('>Roles</a>', $account);
        self::assertStringNotContainsString('authentication.menu.', $account);

        $roles = (string) $kernel->handle($this->get('/roles', $ada))->getBody();

        self::assertStringContainsString('admin', $roles);
        self::assertStringContainsString('member', $roles);
        self::assertStringNotContainsString('authentication.roles.', $roles);
        self::assertStringNotContainsString('authentication.permission.', $roles);
    }

    /**
     * Editing a role moves REAL access: Bob's member role gains user.manage
     * and his very next request may enter /users — no relog, no new grant.
     */
    public function testEditingARoleChangesItsHoldersAccessOnTheirNextRequest(): void
    {
        $kernel = $this->kernel();
        $bob = $this->login($kernel, 'bob@liminal.test');

        // Before: no Users menu entry, /users forbidden.
        self::assertStringNotContainsString('href="/users"', (string) $kernel->handle($this->get('/account', $bob))->getBody());
        self::assertSame(403, $kernel->handle($this->get('/users', $bob))->getStatusCode());

        $ada = $this->login($kernel, 'ada@liminal.test');
        $memberId = $this->roleIdOf('member');

        $detail = $kernel->handle($this->get('/roles/' . $memberId, $ada));

        $update = $kernel->handle($this->post(
            '/roles/' . $memberId,
            [
                'label' => 'Member',
                'permissions' => ['authentication.user.manage'],
                '_token' => $this->tokenFrom((string) $detail->getBody()),
            ],
            $ada,
        ));

        self::assertSame(302, $update->getStatusCode());

        // After: same session, next request, the menu and the page follow.
        self::assertStringContainsString('href="/users"', (string) $kernel->handle($this->get('/account', $bob))->getBody());
        self::assertSame(200, $kernel->handle($this->get('/users', $bob))->getStatusCode());
    }

    public function testCreatingARoleThroughTheFormFiltersTamperedPermissionCodes(): void
    {
        $kernel = $this->kernel();
        $ada = $this->login($kernel, 'ada@liminal.test');

        $form = $kernel->handle($this->get('/roles/create', $ada));

        $created = $kernel->handle($this->post(
            '/roles/create',
            [
                'code' => 'auditor',
                'label' => 'Auditor',
                'permissions' => ['authentication.user.manage', 'made.up.code'],
                '_token' => $this->tokenFrom((string) $form->getBody()),
            ],
            $ada,
        ));

        self::assertSame(302, $created->getStatusCode());

        // The declared code landed; the invented one was never born.
        self::assertEquals(1, $this->dbal->fetchOne(
            "SELECT COUNT(*) FROM core_role_permission rp
             JOIN core_role r ON r.id = rp.role_id WHERE r.code = 'auditor'",
        ));
        self::assertEquals(0, $this->dbal->fetchOne(
            "SELECT COUNT(*) FROM core_role_permission WHERE permission_code = 'made.up.code'",
        ));
    }

    public function testADuplicateRoleCodeIsRefusedPolitely(): void
    {
        $kernel = $this->kernel();
        $ada = $this->login($kernel, 'ada@liminal.test');

        $form = $kernel->handle($this->get('/roles/create', $ada));

        $response = $kernel->handle($this->post(
            '/roles/create',
            ['code' => 'member', 'label' => 'Impostor', '_token' => $this->tokenFrom((string) $form->getBody())],
            $ada,
        ));

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/roles/create', $response->getHeaderLine('Location'));

        $reloaded = (string) $kernel->handle($this->get('/roles/create', $ada))->getBody();
        self::assertStringContainsString('A role with that code already exists.', $reloaded);
        self::assertEquals(2, $this->dbal->fetchOne('SELECT COUNT(*) FROM core_role'));
    }

    public function testDeletingTheAdminRoleIsRefusedAndDeletingAnotherIsAMassRevocation(): void
    {
        $kernel = $this->kernel();
        $bob = $this->login($kernel, 'bob@liminal.test');
        $ada = $this->login($kernel, 'ada@liminal.test');

        $adminId = $this->roleIdOf('admin');
        $memberId = $this->roleIdOf('member');

        // The admin page offers no delete form; a hand-crafted POST bounces.
        $adminPage = (string) $kernel->handle($this->get('/roles/' . $adminId, $ada))->getBody();
        self::assertStringNotContainsString('/roles/' . $adminId . '/delete', $adminPage);
        self::assertStringContainsString('bootstrap anchor', $adminPage);

        $refused = $kernel->handle($this->post(
            '/roles/' . $adminId . '/delete',
            ['_token' => $this->tokenFrom($adminPage)],
            $ada,
        ));

        self::assertSame(302, $refused->getStatusCode());
        self::assertEquals(1, $this->dbal->fetchOne("SELECT COUNT(*) FROM core_role WHERE code = 'admin'"));

        // Deleting member revokes Bob everywhere: forced logout on his next request.
        $deleted = $kernel->handle($this->post(
            '/roles/' . $memberId . '/delete',
            ['_token' => $this->tokenFrom($adminPage)],
            $ada,
        ));

        self::assertSame(302, $deleted->getStatusCode());
        self::assertSame('/roles', $deleted->getHeaderLine('Location'));
        self::assertSame(302, $kernel->handle($this->get('/account', $bob))->getStatusCode());
    }

    public function testAStoredButUndeclaredPermissionRendersInertAndDisappearsAtTheNextSave(): void
    {
        $kernel = $this->kernel();
        $ada = $this->login($kernel, 'ada@liminal.test');
        $memberId = $this->roleIdOf('member');

        // A module was removed; its permission code lingers in the database.
        $this->dbal->insert('core_role_permission', [
            'role_id' => $memberId,
            'permission_code' => 'ghost.module.permission',
        ]);

        // Bob still signs in and browses — a stale code 500s nobody.
        $bob = $this->login($kernel, 'bob@liminal.test');
        self::assertSame(200, $kernel->handle($this->get('/account', $bob))->getStatusCode());

        $detail = $kernel->handle($this->get('/roles/' . $memberId, $ada));
        $html = (string) $detail->getBody();

        self::assertStringContainsString('no longer declared', $html);
        self::assertStringContainsString('ghost.module.permission', $html);

        // Saving the form rewrites the exact set: the ghost is gone.
        $update = $kernel->handle($this->post(
            '/roles/' . $memberId,
            ['label' => 'Member', '_token' => $this->tokenFrom($html)],
            $ada,
        ));

        self::assertSame(302, $update->getStatusCode());
        self::assertEquals(0, $this->dbal->fetchOne(
            "SELECT COUNT(*) FROM core_role_permission WHERE permission_code = 'ghost.module.permission'",
        ));
    }

    public function testTheScreensAreForbiddenWithoutTheRolePermission(): void
    {
        $kernel = $this->kernel();

        // Bob's member role holds nothing; user.manage alone must not open /roles.
        $bob = $this->login($kernel, 'bob@liminal.test');
        self::assertSame(403, $kernel->handle($this->get('/roles', $bob))->getStatusCode());

        $ada = $this->login($kernel, 'ada@liminal.test');
        self::assertSame(404, $kernel->handle($this->get('/roles/999', $ada))->getStatusCode());
    }

    private function roleIdOf(string $code): int
    {
        $id = $this->dbal->fetchOne('SELECT id FROM core_role WHERE code = ?', [$code]);
        self::assertIsNumeric($id);

        return (int) $id;
    }
}
