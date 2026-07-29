<?php

declare(strict_types=1);

namespace Liminal\Tests\Unit\Security;

use DateTimeImmutable;
use Liminal\Lib\Security\Csrf\CsrfTokenManager;
use Liminal\Lib\Security\Session\Session;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CsrfTokenManager::class)]
final class CsrfTokenManagerTest extends TestCase
{
    public function testTheTokenIsStableWithinOneSession(): void
    {
        $manager = new CsrfTokenManager();
        $session = Session::fresh('id', new DateTimeImmutable());

        self::assertSame($manager->token($session), $manager->token($session));
    }

    public function testItsFirstGenerationIsTheSessionsFirstWrite(): void
    {
        $manager = new CsrfTokenManager();
        $session = Session::fresh('id', new DateTimeImmutable());

        self::assertFalse($session->isDirty());

        $manager->token($session);

        self::assertTrue($session->isDirty());
    }

    public function testValidationRefusesWrongMissingAndEmptyTokens(): void
    {
        $manager = new CsrfTokenManager();
        $session = Session::fresh('id', new DateTimeImmutable());

        // No token ever generated: nothing validates, not even ''.
        self::assertFalse($manager->validate($session, ''));
        self::assertFalse($manager->validate($session, 'anything'));

        $token = $manager->token($session);

        self::assertTrue($manager->validate($session, $token));
        self::assertFalse($manager->validate($session, 'not-' . $token));
        self::assertFalse($manager->validate($session, ''));
    }

    public function testRemovingTheKeyRotatesTheToken(): void
    {
        $manager = new CsrfTokenManager();
        $session = Session::fresh('id', new DateTimeImmutable());

        $first = $manager->token($session);
        $session->remove(CsrfTokenManager::KEY);

        self::assertNotSame($first, $manager->token($session));
    }
}
