<?php

declare(strict_types=1);

namespace Liminal\Tests\Integration;

use Liminal\Module\Authentication\Console\TokenCreateCommand;
use Liminal\Module\Authentication\Console\TokenListCommand;
use Liminal\Module\Authentication\Console\TokenRevokeCommand;
use Liminal\Support\Env;
use Liminal\Tests\Integration\Support\BrowserJourney;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * API tokens end to end: minted once from the console, hash-only in
 * storage, honoured by the whole request pipeline as a bearer credential —
 * and revoked into an immediate 401. The round-trip is the proof that the
 * three seams compose: no cookie, no CSRF token, the company from the
 * request, a page rendered under the token's identity.
 */
#[CoversNothing]
final class ApiTokenTest extends IntegrationTestCase
{
    use BrowserJourney;

    protected function setUp(): void
    {
        $dsn = Env::nullableString('LIMINAL_TEST_DSN');

        if ($dsn === null) {
            self::markTestSkipped('LIMINAL_TEST_DSN is not set; skipping api token test.');
        }

        $this->bootstrapInstance($dsn);
        $this->seedAdaAndBob([]);
    }

    protected function tearDown(): void
    {
        $this->restoreDsn();
    }

    public function testTheLifecycleCommandsMintListAndRevokeHashOnly(): void
    {
        $create = $this->tester(TokenCreateCommand::class);
        $create->execute(['email' => 'ada@liminal.test', '--label' => 'ci']);
        $create->assertCommandIsSuccessful();

        $raw = $this->rawTokenFrom($create->getDisplay());
        // The caution box wraps lines and prefixes them: flatten first.
        $flattened = (string) preg_replace('/[\s!]+/', ' ', $create->getDisplay());
        self::assertStringContainsString('never be shown again', $flattened);

        // Storage holds the sha256 and only that.
        self::assertEquals(1, $this->dbal->fetchOne('SELECT COUNT(*) FROM core_api_token'));
        self::assertEquals(hash('sha256', $raw), $this->dbal->fetchOne('SELECT token_hash FROM core_api_token'));

        $list = $this->tester(TokenListCommand::class);
        $list->execute(['email' => 'ada@liminal.test']);
        self::assertStringContainsString('ci', $list->getDisplay());
        self::assertStringContainsString('never', $list->getDisplay());

        $revoke = $this->tester(TokenRevokeCommand::class);
        $revoke->execute(['id' => '1']);
        $revoke->assertCommandIsSuccessful();
        self::assertEquals(0, $this->dbal->fetchOne('SELECT COUNT(*) FROM core_api_token'));

        // Both facts hit the audit trail — and never the secret.
        self::assertEquals(1, $this->dbal->fetchOne("SELECT COUNT(*) FROM core_audit_event WHERE event = 'TOKEN_CREATED'"));
        self::assertEquals(1, $this->dbal->fetchOne("SELECT COUNT(*) FROM core_audit_event WHERE event = 'TOKEN_REVOKED'"));
        self::assertEquals(0, $this->dbal->fetchOne(
            'SELECT COUNT(*) FROM core_audit_event WHERE payload LIKE ?',
            ['%' . substr($raw, 8, 20) . '%'],
        ));
    }

    public function testABearerCredentialRidesTheWholePipeline(): void
    {
        $raw = $this->mint('ada@liminal.test');

        $kernel = $this->kernel();

        // No cookie, no CSRF token — the account page renders under the
        // token's identity all the same.
        $account = $kernel->handle(
            $this->get('/account')->withHeader('Authorization', 'Bearer ' . $raw),
        );
        self::assertSame(200, $account->getStatusCode());
        self::assertStringContainsString('ada@liminal.test', (string) $account->getBody());

        // The credential leaves a usage trace.
        self::assertNotNull($this->dbal->fetchOne('SELECT last_used_at FROM core_api_token'));

        // A wrong token is 401 with the RFC 6750 header, JSON envelope.
        $bad = $kernel->handle(
            $this->get('/account')
                ->withHeader('Accept', 'application/json')
                ->withHeader('Authorization', 'Bearer liminal_wrong'),
        );
        self::assertSame(401, $bad->getStatusCode());
        self::assertSame('Bearer error="invalid_token"', $bad->getHeaderLine('WWW-Authenticate'));
        self::assertStringContainsString('"error"', (string) $bad->getBody());

        // A company outside the accessible set is refused, never replaced.
        $foreign = $kernel->handle(
            $this->get('/account')
                ->withHeader('Accept', 'application/json')
                ->withHeader('Authorization', 'Bearer ' . $raw)
                ->withHeader('X-Liminal-Company', '99'),
        );
        self::assertSame(403, $foreign->getStatusCode());

        // Revocation is an immediate 401.
        $this->tester(TokenRevokeCommand::class)->execute(['id' => '1']);
        $revoked = $kernel->handle(
            $this->get('/account')
                ->withHeader('Accept', 'application/json')
                ->withHeader('Authorization', 'Bearer ' . $raw),
        );
        self::assertSame(401, $revoked->getStatusCode());
    }

    private function mint(string $email): string
    {
        $create = $this->tester(TokenCreateCommand::class);
        $create->execute(['email' => $email]);
        $create->assertCommandIsSuccessful();

        return $this->rawTokenFrom($create->getDisplay());
    }

    private function rawTokenFrom(string $display): string
    {
        if (preg_match('/(liminal_[A-Za-z0-9_-]{43})/', $display, $matches) !== 1) {
            self::fail('The create command did not print a raw token.');
        }

        return $matches[1];
    }

    /**
     * @param class-string<Command> $command
     */
    private function tester(string $command): CommandTester
    {
        $resolved = $this->kernel()->container()->get($command);
        self::assertInstanceOf(Command::class, $resolved);

        return new CommandTester($resolved);
    }
}
