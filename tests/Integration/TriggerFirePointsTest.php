<?php

declare(strict_types=1);

namespace Liminal\Tests\Integration;

use Liminal\Kernel;
use Liminal\Module\Authentication\Console\RoleGrantCommand;
use Liminal\Support\Env;
use Liminal\Tests\Integration\Support\BrowserJourney;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The fire points through real requests: every administrative mutation leaves
 * exactly one audit row, with the actor when a browser did it and without one
 * when the console did — and idempotent no-ops leave nothing at all.
 */
#[CoversNothing]
final class TriggerFirePointsTest extends IntegrationTestCase
{
    use BrowserJourney;

    protected function setUp(): void
    {
        $dsn = Env::nullableString('LIMINAL_TEST_DSN');

        if ($dsn === null) {
            self::markTestSkipped('LIMINAL_TEST_DSN is not set; skipping trigger fire point test.');
        }

        $this->bootstrapInstance($dsn);
        $this->seedAdaAndBob([
            'authentication.user.manage',
            'authentication.role.manage',
            'thirdparty.read',
            'thirdparty.manage',
        ]);
    }

    protected function tearDown(): void
    {
        $this->restoreDsn();
    }

    public function testAWebMutationIsAuditedWithItsActorAndThePayloadRoundTrips(): void
    {
        $kernel = $this->kernel();
        $ada = $this->login($kernel, 'ada@liminal.test');
        $adaId = $this->dbal->fetchOne("SELECT id FROM core_user WHERE email = 'ada@liminal.test'");

        $form = $kernel->handle($this->get('/users/create', $ada));
        $kernel->handle($this->post(
            '/users/create',
            ['email' => 'carol@liminal.test', 'display_name' => 'Carol', '_token' => $this->tokenFrom((string) $form->getBody())],
            $ada,
        ));

        $row = $this->dbal->fetchAssociative(
            "SELECT * FROM core_audit_event WHERE event = 'USER_CREATED'",
        );

        self::assertNotFalse($row);
        self::assertEquals($adaId, $row['actor_id']);
        self::assertEquals(1, $row['company_id']);

        self::assertIsString($row['payload']);
        $payload = json_decode($row['payload'], true);
        self::assertIsArray($payload);
        self::assertSame('carol@liminal.test', $payload['email']);
        self::assertIsInt($payload['user_id']);
        // The one-time password never enters a payload.
        self::assertArrayNotHasKey('password', $payload);
    }

    public function testAThirdpartyLifecycleLeavesOneRowPerMutation(): void
    {
        $kernel = $this->kernel();
        $ada = $this->login($kernel, 'ada@liminal.test');

        $form = $kernel->handle($this->get('/thirdparties/create', $ada));
        $created = $kernel->handle($this->post(
            '/thirdparties/create',
            ['code' => 'ACME-01', 'name' => 'Acme', '_token' => $this->tokenFrom((string) $form->getBody())],
            $ada,
        ));
        $location = $created->getHeaderLine('Location');

        $detail = $kernel->handle($this->get($location, $ada));
        $token = $this->tokenFrom((string) $detail->getBody());

        $kernel->handle($this->post($location, ['code' => 'ACME-01', 'name' => 'Acme Ltd', '_token' => $token], $ada));
        $kernel->handle($this->post($location . '/delete', ['_token' => $token], $ada));

        self::assertSame(
            ['THIRDPARTY_CREATED', 'THIRDPARTY_UPDATED', 'THIRDPARTY_DELETED'],
            $this->dbal->fetchFirstColumn(
                "SELECT event FROM core_audit_event WHERE event LIKE 'THIRDPARTY%' ORDER BY id",
            ),
        );
    }

    /**
     * grant() is idempotent — and the trigger follows the truth: a repeat that
     * mutates nothing fires nothing.
     */
    public function testAnIdempotentGrantFiresOnlyOnRealMutation(): void
    {
        $kernel = $this->kernel();
        $ada = $this->login($kernel, 'ada@liminal.test');
        $bobId = $this->dbal->fetchOne("SELECT id FROM core_user WHERE email = 'bob@liminal.test'");
        $adminRole = $this->dbal->fetchOne("SELECT id FROM core_role WHERE code = 'admin'");
        self::assertIsNumeric($bobId);
        self::assertIsNumeric($adminRole);

        $detail = $kernel->handle($this->get('/users/' . (int) $bobId, $ada));
        $token = $this->tokenFrom((string) $detail->getBody());
        $body = ['company' => '1', 'role' => (string) (int) $adminRole, '_token' => $token];

        $kernel->handle($this->post('/users/' . (int) $bobId . '/grants', $body, $ada));
        $kernel->handle($this->post('/users/' . (int) $bobId . '/grants', $body, $ada));

        self::assertEquals(1, $this->dbal->fetchOne(
            "SELECT COUNT(*) FROM core_audit_event WHERE event = 'GRANT_ADDED'",
        ));
    }

    /**
     * Console fires carry no actor — nobody authenticated — and the event's
     * company is the command's --company, not the bootstrap context.
     */
    public function testAConsoleGrantIsAuditedWithoutAnActorAndWithItsCompany(): void
    {
        $this->seedSecondCompany(granting: null);

        // Bob needs a grant in company 2's terms: grant member@2 via console.
        $command = new Kernel(self::ROOT)->container()->get(RoleGrantCommand::class);
        self::assertInstanceOf(RoleGrantCommand::class, $command);

        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            'email' => 'bob@liminal.test',
            'role' => 'member',
            '--company' => '2',
        ]));

        $row = $this->dbal->fetchAssociative(
            "SELECT * FROM core_audit_event WHERE event = 'GRANT_ADDED'",
        );

        self::assertNotFalse($row);
        self::assertNull($row['actor_id']);
        // The forensic column agrees with the payload — the review's trap closed.
        self::assertEquals(2, $row['company_id']);
    }
}
