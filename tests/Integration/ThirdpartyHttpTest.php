<?php

declare(strict_types=1);

namespace Liminal\Tests\Integration;

use Liminal\Support\Env;
use Liminal\Tests\Integration\Support\BrowserJourney;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * The thirdparty screens through a browser — where the two scope layers
 * finally meet a user: the working company decides what the list shows, the
 * switcher moves it, and a URL into another company answers 404.
 */
#[CoversNothing]
final class ThirdpartyHttpTest extends IntegrationTestCase
{
    use BrowserJourney;

    protected function setUp(): void
    {
        $dsn = Env::nullableString('LIMINAL_TEST_DSN');

        if ($dsn === null) {
            self::markTestSkipped('LIMINAL_TEST_DSN is not set; skipping thirdparty http test.');
        }

        $this->bootstrapInstance($dsn);
        $this->seedAdaAndBob(['thirdparty.read', 'thirdparty.manage']);
    }

    protected function tearDown(): void
    {
        $this->restoreDsn();
    }

    public function testTheMenuAndListRenderTranslatedAndGated(): void
    {
        $kernel = $this->kernel();
        $ada = $this->login($kernel, 'ada@liminal.test');

        $account = (string) $kernel->handle($this->get('/account', $ada))->getBody();

        self::assertStringContainsString('>Third parties</a>', $account);
        self::assertStringNotContainsString('thirdparty.menu.', $account);

        $list = (string) $kernel->handle($this->get('/thirdparties', $ada))->getBody();

        self::assertStringContainsString('No third party here yet.', $list);
        self::assertStringNotContainsString('thirdparty.list.', $list);

        // Bob's member role holds nothing: no menu entry, no page.
        $bob = $this->login($kernel, 'bob@liminal.test');
        self::assertStringNotContainsString('href="/thirdparties"', (string) $kernel->handle($this->get('/account', $bob))->getBody());
        self::assertSame(403, $kernel->handle($this->get('/thirdparties', $bob))->getStatusCode());
    }

    /**
     * THE two-layer proof over HTTP: ada reaches both companies — the security
     * fence admits both — yet each list shows only the company she works in,
     * and the switcher is what moves the line.
     */
    public function testTheListFollowsTheWorkingCompanyAndCrossCompanyUrlsAre404(): void
    {
        $this->seedSecondCompany(granting: 'ada@liminal.test');
        $this->seedThirdparty(1, 'HERE-1', 'Main Street Retail');
        $this->seedThirdparty(2, 'THERE-1', 'Acme Wholesale');
        $acmeRowId = (int) $this->dbal->lastInsertId();

        $kernel = $this->kernel();
        $ada = $this->login($kernel, 'ada@liminal.test');

        // Working in MAIN: only MAIN's thirdparty.
        $inMain = (string) $kernel->handle($this->get('/thirdparties', $ada))->getBody();
        self::assertStringContainsString('Main Street Retail', $inMain);
        self::assertStringNotContainsString('Acme Wholesale', $inMain);

        // The other company's row is accessible to her — and still 404 here,
        // because the repository narrows to the WORKING company.
        self::assertSame(404, $kernel->handle($this->get('/thirdparties/' . $acmeRowId, $ada))->getStatusCode());

        // Switch to ACME: the same URL now answers, the list flips.
        $account = (string) $kernel->handle($this->get('/account', $ada))->getBody();
        $kernel->handle($this->post(
            '/switch-company',
            ['company' => '2', '_token' => $this->tokenFrom($account)],
            $ada,
        ));

        $inAcme = (string) $kernel->handle($this->get('/thirdparties', $ada))->getBody();
        self::assertStringContainsString('Acme Wholesale', $inAcme);
        self::assertStringNotContainsString('Main Street Retail', $inAcme);
        self::assertSame(200, $kernel->handle($this->get('/thirdparties/' . $acmeRowId, $ada))->getStatusCode());
    }

    public function testSearchAndPaginationWorkOverHttp(): void
    {
        for ($i = 1; $i <= 26; ++$i) {
            $this->seedThirdparty(1, sprintf('CU-%02d', $i), sprintf('Customer %02d', $i));
        }
        $this->seedThirdparty(1, 'ODD', '100% Cotton');

        $kernel = $this->kernel();
        $ada = $this->login($kernel, 'ada@liminal.test');

        // 27 rows: two pages, the second carrying the tail.
        $first = (string) $kernel->handle($this->get('/thirdparties', $ada))->getBody();
        self::assertStringContainsString('Page 1 of 2 (27 total)', $first);
        self::assertStringContainsString('/thirdparties?page=2', $first);

        $second = (string) $kernel->handle($this->get('/thirdparties?page=2', $ada))->getBody();
        self::assertStringContainsString('Page 2 of 2', $second);

        // The search narrows — and a literal % in the query is literal.
        $searched = (string) $kernel->handle($this->get('/thirdparties?q=' . rawurlencode('100%'), $ada))->getBody();
        self::assertStringContainsString('100% Cotton', $searched);
        self::assertStringContainsString('Page 1 of 1 (1 total)', $searched);
    }

    public function testTheDetailShowsTheRecord(): void
    {
        $this->seedThirdparty(1, 'FULL', 'Full Record', [
            'alias' => 'The Complete One',
            'is_customer' => 1,
            'is_supplier' => 1,
            'email' => 'contact@full.example',
            'phone' => '+33 1 23 45 67 89',
            'address' => '1 rue de la Paix',
            'zip' => '75002',
            'town' => 'Paris',
            'country_code' => 'FR',
            'vat_number' => 'FR12345678901',
            'notes' => "First line.\nSecond line.",
        ]);
        $id = (int) $this->dbal->lastInsertId();

        $kernel = $this->kernel();
        $ada = $this->login($kernel, 'ada@liminal.test');

        $html = (string) $kernel->handle($this->get('/thirdparties/' . $id, $ada))->getBody();

        self::assertStringContainsString('Full Record', $html);
        self::assertStringContainsString('The Complete One', $html);
        self::assertStringContainsString('customer', $html);
        self::assertStringContainsString('supplier', $html);
        self::assertStringContainsString('contact@full.example', $html);
        self::assertStringContainsString('75002 Paris', $html);
        self::assertStringContainsString('FR12345678901', $html);
        self::assertStringNotContainsString('thirdparty.field.', $html);

        self::assertSame(404, $kernel->handle($this->get('/thirdparties/999', $ada))->getStatusCode());
    }

    public function testCreateEditDeleteRoundTripThroughTheForms(): void
    {
        $kernel = $this->kernel();
        $ada = $this->login($kernel, 'ada@liminal.test');

        // Create through the form; prePersist stamps the working company.
        $form = $kernel->handle($this->get('/thirdparties/create', $ada));
        $created = $kernel->handle($this->post(
            '/thirdparties/create',
            [
                'code' => 'ACME-01',
                'name' => 'Acme Industries',
                'customer' => '1',
                'email' => 'sales@acme.example',
                '_token' => $this->tokenFrom((string) $form->getBody()),
            ],
            $ada,
        ));

        self::assertSame(302, $created->getStatusCode());
        $location = $created->getHeaderLine('Location');
        self::assertMatchesRegularExpression('~^/thirdparties/\d+$~', $location);
        self::assertEquals(1, $this->dbal->fetchOne(
            "SELECT company_id FROM thirdparty_thirdparty WHERE code = 'ACME-01'",
        ));

        $detail = (string) $kernel->handle($this->get($location, $ada))->getBody();
        self::assertStringContainsString('Third party created.', $detail);
        self::assertStringContainsString('Acme Industries', $detail);

        // Edit: rename, flip to supplier, deactivate.
        $updated = $kernel->handle($this->post(
            $location,
            [
                'code' => 'ACME-01',
                'name' => 'Acme Industries Ltd',
                'supplier' => '1',
                'active' => '0',
                '_token' => $this->tokenFrom($detail),
            ],
            $ada,
        ));

        self::assertSame(302, $updated->getStatusCode());

        $after = (string) $kernel->handle($this->get($location, $ada))->getBody();
        self::assertStringContainsString('Third party updated.', $after);
        self::assertStringContainsString('Acme Industries Ltd', $after);
        self::assertStringContainsString('supplier', $after);
        self::assertStringContainsString('inactive', $after);

        // Delete: gone from the list, flash says so.
        $deleted = $kernel->handle($this->post(
            $location . '/delete',
            ['_token' => $this->tokenFrom($after)],
            $ada,
        ));

        self::assertSame(302, $deleted->getStatusCode());
        self::assertSame('/thirdparties', $deleted->getHeaderLine('Location'));

        $list = (string) $kernel->handle($this->get('/thirdparties', $ada))->getBody();
        self::assertStringContainsString('Third party deleted.', $list);
        self::assertStringNotContainsString('Acme Industries', $list);
        self::assertEquals(0, $this->dbal->fetchOne('SELECT COUNT(*) FROM thirdparty_thirdparty'));
    }

    /**
     * The composite unique through the browser: the same code refused where it
     * exists, welcome in the company next door.
     */
    public function testTheSameCodeIsRefusedHereAndWelcomeNextDoor(): void
    {
        $this->seedSecondCompany(granting: 'ada@liminal.test');
        $this->seedThirdparty(1, 'SHARED', 'First Holder');

        $kernel = $this->kernel();
        $ada = $this->login($kernel, 'ada@liminal.test');

        $form = $kernel->handle($this->get('/thirdparties/create', $ada));
        $token = $this->tokenFrom((string) $form->getBody());

        // Same company: politely refused.
        $refused = $kernel->handle($this->post(
            '/thirdparties/create',
            ['code' => 'shared', 'name' => 'Impostor', '_token' => $token],
            $ada,
        ));

        self::assertSame(302, $refused->getStatusCode());
        self::assertSame('/thirdparties/create', $refused->getHeaderLine('Location'));

        $reloaded = (string) $kernel->handle($this->get('/thirdparties/create', $ada))->getBody();
        self::assertStringContainsString('already exists in this company', $reloaded);

        // Wait — 'shared' vs 'SHARED': the pre-check compares exactly, the
        // collation decides; either way nothing landed.
        self::assertEquals(1, $this->dbal->fetchOne('SELECT COUNT(*) FROM thirdparty_thirdparty'));

        // Switch to ACME: the very same code is welcome there.
        $account = (string) $kernel->handle($this->get('/account', $ada))->getBody();
        $kernel->handle($this->post(
            '/switch-company',
            ['company' => '2', '_token' => $this->tokenFrom($account)],
            $ada,
        ));

        $acmeForm = $kernel->handle($this->get('/thirdparties/create', $ada));
        $welcomed = $kernel->handle($this->post(
            '/thirdparties/create',
            ['code' => 'SHARED', 'name' => 'Second Holder', '_token' => $this->tokenFrom((string) $acmeForm->getBody())],
            $ada,
        ));

        self::assertSame(302, $welcomed->getStatusCode());
        self::assertMatchesRegularExpression('~^/thirdparties/\d+$~', $welcomed->getHeaderLine('Location'));
        self::assertEquals(2, $this->dbal->fetchOne('SELECT COUNT(*) FROM thirdparty_thirdparty'));
    }

    public function testValidationRefusalsFlashPolitely(): void
    {
        $kernel = $this->kernel();
        $ada = $this->login($kernel, 'ada@liminal.test');

        $form = $kernel->handle($this->get('/thirdparties/create', $ada));
        $token = $this->tokenFrom((string) $form->getBody());

        $noName = $kernel->handle($this->post(
            '/thirdparties/create',
            ['code' => 'OK', 'name' => '   ', '_token' => $token],
            $ada,
        ));
        self::assertSame('/thirdparties/create', $noName->getHeaderLine('Location'));
        self::assertStringContainsString(
            'The name is required.',
            (string) $kernel->handle($this->get('/thirdparties/create', $ada))->getBody(),
        );

        $badEmail = $kernel->handle($this->post(
            '/thirdparties/create',
            ['code' => 'OK', 'name' => 'Fine', 'email' => 'not-an-email', '_token' => $token],
            $ada,
        ));
        self::assertSame('/thirdparties/create', $badEmail->getHeaderLine('Location'));

        self::assertEquals(0, $this->dbal->fetchOne('SELECT COUNT(*) FROM thirdparty_thirdparty'));
    }

    /**
     * The split, both ways: read alone opens the pages and none of the writes;
     * manage without read opens NOTHING — manage presumes read, or a
     * manage-only actor would create records on pages they may never see.
     */
    public function testReadAloneCannotWriteAndManageAlonePresumesRead(): void
    {
        // Bob's member role gains read only.
        $this->dbal->executeStatement(
            "INSERT INTO core_role_permission (role_id, permission_code)
             SELECT id, 'thirdparty.read' FROM core_role WHERE code = 'member'",
        );

        $kernel = $this->kernel();
        $bob = $this->login($kernel, 'bob@liminal.test');

        self::assertSame(200, $kernel->handle($this->get('/thirdparties', $bob))->getStatusCode());
        self::assertSame(403, $kernel->handle($this->get('/thirdparties/create', $bob))->getStatusCode());

        // The list offers him no create link.
        $list = (string) $kernel->handle($this->get('/thirdparties', $bob))->getBody();
        self::assertStringNotContainsString('href="/thirdparties/create"', $list);

        // A manage-only role opens nothing at all.
        $this->dbal->executeStatement(
            "UPDATE core_role_permission SET permission_code = 'thirdparty.manage'
             WHERE permission_code = 'thirdparty.read'
               AND role_id = (SELECT id FROM core_role WHERE code = 'member')",
        );

        self::assertSame(403, $kernel->handle($this->get('/thirdparties', $bob))->getStatusCode());
        self::assertSame(403, $kernel->handle($this->get('/thirdparties/create', $bob))->getStatusCode());
    }

    /**
     * @param array<string, int|string> $extra
     */
    private function seedThirdparty(int $companyId, string $code, string $name, array $extra = []): void
    {
        $this->dbal->insert('thirdparty_thirdparty', [
            'company_id' => $companyId,
            'code' => $code,
            'name' => $name,
            'is_customer' => 0,
            'is_supplier' => 0,
            'is_active' => 1,
            'created_at' => '2026-07-30 00:00:00',
            'updated_at' => '2026-07-30 00:00:00',
            ...$extra,
        ]);
    }
}
