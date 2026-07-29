<?php

declare(strict_types=1);

namespace Liminal\Tests\Unit\Security;

use DateTimeImmutable;
use Liminal\Lib\Security\Session\Session;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Session::class)]
final class SessionTest extends TestCase
{
    public function testAFreshSessionWithNoWritesIsClean(): void
    {
        $session = Session::fresh('id', new DateTimeImmutable());

        self::assertTrue($session->isNew());
        self::assertFalse($session->isDirty());
        self::assertNull($session->get('anything'));
    }

    public function testAnyWriteMarksTheSessionDirty(): void
    {
        $session = Session::fresh('id', new DateTimeImmutable());
        $session->set('key', 'value');

        self::assertTrue($session->isDirty());
        self::assertSame('value', $session->get('key'));
    }

    public function testPullReturnsTheFlashValueExactlyOnce(): void
    {
        $session = Session::fresh('id', new DateTimeImmutable());
        $session->set('flash', 'you were signed out');

        self::assertSame('you were signed out', $session->pull('flash'));
        self::assertNull($session->pull('flash'));
    }

    public function testRegenerationKeepsThePayloadAndChangesTheId(): void
    {
        $now = new DateTimeImmutable('2026-07-29 10:00:00');
        $session = Session::hydrated('old-id', ['keep' => 'me'], 7, 1, $now->modify('-1 hour'), $now);

        $session->replaceId('new-id', $now);

        self::assertSame('new-id', $session->id());
        self::assertSame('old-id', $session->staleId());
        self::assertSame('me', $session->get('keep'));
        self::assertSame(7, $session->userId());
        // The absolute cap counts from the privilege change.
        self::assertEquals($now, $session->createdAt());
        self::assertTrue($session->isNew());
        self::assertTrue($session->isDirty());
    }

    public function testUnchangedColumnWritesStayClean(): void
    {
        $now = new DateTimeImmutable();
        $session = Session::hydrated('id', [], 7, 1, $now, $now);

        $session->setUserId(7);
        $session->setCompanyId(1);

        self::assertFalse($session->isDirty());
    }
}
