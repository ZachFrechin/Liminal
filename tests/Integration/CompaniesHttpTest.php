<?php

declare(strict_types=1);

namespace Liminal\Tests\Integration;

use Liminal\Support\Env;
use Liminal\Tests\Integration\Support\BrowserJourney;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * The company screens through a browser — ending on the journey the whole
 * phase owes: a company born on the web is immediately livable, because its
 * creation enabled the installed modules for it in the same transaction.
 */
#[CoversNothing]
final class CompaniesHttpTest extends IntegrationTestCase
{
    use BrowserJourney;

    protected function setUp(): void
    {
        $dsn = Env::nullableString('LIMINAL_TEST_DSN');

        if ($dsn === null) {
            self::markTestSkipped('LIMINAL_TEST_DSN is not set; skipping companies http test.');
        }

        $this->bootstrapInstance($dsn);
        $this->seedAdaAndBob([
            'authentication.user.manage',
            'authentication.role.manage',
            'companies.company.manage',
        ]);
    }

    protected function tearDown(): void
    {
        $this->restoreDsn();
    }

    public function testTheMenuAndScreensRenderTranslatedAndGated(): void
    {
        $kernel = $this->kernel();
        $ada = $this->login($kernel, 'ada@liminal.test');

        $account = (string) $kernel->handle($this->get('/account', $ada))->getBody();

        self::assertStringContainsString('>Companies</a>', $account);
        self::assertStringNotContainsString('companies.menu.', $account);

        $list = (string) $kernel->handle($this->get('/companies', $ada))->getBody();

        self::assertStringContainsString('MAIN', $list);
        self::assertStringNotContainsString('companies.list.', $list);

        // Bob's member role holds nothing: no menu entry, no page.
        $bob = $this->login($kernel, 'bob@liminal.test');
        self::assertStringNotContainsString('href="/companies"', (string) $kernel->handle($this->get('/account', $bob))->getBody());
        self::assertSame(403, $kernel->handle($this->get('/companies', $bob))->getStatusCode());
    }

    /**
     * THE 5a obligation, end to end on the web: create a company, grant a
     * user into it, and their session lands there without a single 404 —
     * because creation enabled the installed modules for the new company.
     */
    public function testACompanyBornOnTheWebIsImmediatelyLivable(): void
    {
        $kernel = $this->kernel();
        $ada = $this->login($kernel, 'ada@liminal.test');

        // Create ACME through the form (lowercase input: the policy uppercases).
        $form = $kernel->handle($this->get('/companies/create', $ada));
        $created = $kernel->handle($this->post(
            '/companies/create',
            ['code' => 'acme', 'name' => 'Acme Corp', '_token' => $this->tokenFrom((string) $form->getBody())],
            $ada,
        ));

        self::assertSame(302, $created->getStatusCode());
        $location = $created->getHeaderLine('Location');
        self::assertMatchesRegularExpression('~^/companies/\d+$~', $location);

        // The detail shows both modules enabled for the newborn company.
        $detail = (string) $kernel->handle($this->get($location, $ada))->getBody();
        self::assertStringContainsString('Company created, with every installed module enabled', $detail);
        self::assertStringContainsString('Acme Corp', $detail);
        self::assertSame(5, preg_match_all('/>\s*enabled\s*</', $detail));

        // Grant Bob a role in ACME through the user screen.
        $acmeId = (int) substr($location, strlen('/companies/'));
        $bobId = $this->dbal->fetchOne("SELECT id FROM core_user WHERE email = 'bob@liminal.test'");
        $memberId = $this->dbal->fetchOne("SELECT id FROM core_role WHERE code = 'member'");
        self::assertIsNumeric($bobId);
        self::assertIsNumeric($memberId);

        $userPage = $kernel->handle($this->get('/users/' . (int) $bobId, $ada));
        $granted = $kernel->handle($this->post(
            '/users/' . (int) $bobId . '/grants',
            [
                'company' => (string) $acmeId,
                'role' => (string) (int) $memberId,
                '_token' => $this->tokenFrom((string) $userPage->getBody()),
            ],
            $ada,
        ));

        self::assertSame(302, $granted->getStatusCode());

        // Bob signs in, switches to ACME, and EVERY page answers — no 404s.
        $bob = $this->login($kernel, 'bob@liminal.test');
        $account = $kernel->handle($this->get('/account', $bob));
        self::assertSame(200, $account->getStatusCode());

        $switch = $kernel->handle($this->post(
            '/switch-company',
            ['company' => (string) $acmeId, '_token' => $this->tokenFrom((string) $account->getBody())],
            $bob,
        ));
        self::assertSame(302, $switch->getStatusCode());

        $inAcme = $kernel->handle($this->get('/account', $bob));

        self::assertSame(200, $inAcme->getStatusCode());
        self::assertStringContainsString('"switcher-label">Acme Corp<', (string) $inAcme->getBody());
    }

    public function testInvalidAndDuplicateCodesAreRefusedPolitely(): void
    {
        $kernel = $this->kernel();
        $ada = $this->login($kernel, 'ada@liminal.test');

        $form = $kernel->handle($this->get('/companies/create', $ada));
        $token = $this->tokenFrom((string) $form->getBody());

        // An unusable code flashes back to the form.
        $invalid = $kernel->handle($this->post(
            '/companies/create',
            ['code' => '1BAD', 'name' => 'Bad', '_token' => $token],
            $ada,
        ));
        self::assertSame('/companies/create', $invalid->getHeaderLine('Location'));

        $reloaded = (string) $kernel->handle($this->get('/companies/create', $ada))->getBody();
        self::assertStringContainsString('valid code and a name', $reloaded);

        // A duplicate code too — MAIN exists, and main collides through the
        // case-insensitive collation.
        $duplicate = $kernel->handle($this->post(
            '/companies/create',
            ['code' => 'main', 'name' => 'Impostor', '_token' => $token],
            $ada,
        ));
        self::assertSame('/companies/create', $duplicate->getHeaderLine('Location'));

        $again = (string) $kernel->handle($this->get('/companies/create', $ada))->getBody();
        self::assertStringContainsString('A company with that code already exists.', $again);
        self::assertEquals(1, $this->dbal->fetchOne('SELECT COUNT(*) FROM core_company'));
    }

    public function testRenamingKeepsTheCodeAndTheJourneyAssertionsHonest(): void
    {
        $kernel = $this->kernel();
        $ada = $this->login($kernel, 'ada@liminal.test');

        $detail = $kernel->handle($this->get('/companies/1', $ada));

        $renamed = $kernel->handle($this->post(
            '/companies/1',
            ['name' => 'Main office', '_token' => $this->tokenFrom((string) $detail->getBody())],
            $ada,
        ));

        self::assertSame(302, $renamed->getStatusCode());

        $after = (string) $kernel->handle($this->get('/companies/1', $ada))->getBody();
        self::assertStringContainsString('Company renamed.', $after);
        self::assertStringContainsString('Main office', $after);
        self::assertStringContainsString('MAIN', $after);

        // The shell switcher follows the new name immediately.
        self::assertStringContainsString(
            '"switcher-label">Main office<',
            (string) $kernel->handle($this->get('/account', $ada))->getBody(),
        );

        self::assertSame(404, $kernel->handle($this->get('/companies/999', $ada))->getStatusCode());
    }

    public function testTheIdentityPanelSavesAndDegradesEmptyToNull(): void
    {
        $kernel = $this->kernel();
        $ada = $this->login($kernel, 'ada@liminal.test');

        $detail = (string) $kernel->handle($this->get('/companies/1', $ada))->getBody();
        self::assertStringContainsString('Identity', $detail);
        $token = $this->tokenFrom($detail);

        $saved = $kernel->handle($this->post(
            '/companies/1/identity',
            [
                'address' => '12 rue des Lilas',
                'zip' => '75011',
                'town' => 'Paris',
                'country_code' => 'fr',
                'vat_number' => 'FR12345678901',
                'registration' => 'RCS Paris 123 456 789',
                'legal_mentions' => 'Late penalty: 3x legal rate.',
                '_token' => $token,
            ],
            $ada,
        ));
        self::assertSame(302, $saved->getStatusCode());

        $after = (string) $kernel->handle($this->get('/companies/1', $ada))->getBody();
        self::assertStringContainsString('Identity saved.', $after);
        self::assertStringContainsString('12 rue des Lilas', $after);
        // The country code is normalised to uppercase on the way in.
        self::assertEquals('FR', $this->dbal->fetchOne('SELECT country_code FROM core_company WHERE id = 1'));

        // The mutation is audited.
        self::assertEquals(1, $this->dbal->fetchOne("SELECT COUNT(*) FROM core_audit_event WHERE event = 'COMPANY_UPDATED'"));

        // Clearing a field stores NULL, never an empty string.
        $kernel->handle($this->post(
            '/companies/1/identity',
            ['address' => '', 'zip' => '75011', 'town' => 'Paris', '_token' => $token],
            $ada,
        ));
        self::assertNull($this->dbal->fetchOne('SELECT address FROM core_company WHERE id = 1'));
        self::assertNull($this->dbal->fetchOne('SELECT vat_number FROM core_company WHERE id = 1'));
    }
}
