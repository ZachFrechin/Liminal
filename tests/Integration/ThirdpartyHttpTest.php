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
