<?php

declare(strict_types=1);

namespace Liminal\Tests\Unit\Security;

use Liminal\Lib\Security\Password\PasswordHasher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PasswordHasher::class)]
final class PasswordHasherTest extends TestCase
{
    public function testVerifyAcceptsAHashItProduced(): void
    {
        $hasher = new PasswordHasher();

        self::assertTrue($hasher->verify('correct horse battery staple', $hasher->hash('correct horse battery staple')));
    }

    public function testVerifyRefusesTheWrongPassword(): void
    {
        $hasher = new PasswordHasher();

        self::assertFalse($hasher->verify('wrong', $hasher->hash('right')));
    }

    public function testAForeignCostFactorNeedsARehash(): void
    {
        $hasher = new PasswordHasher();
        $weak = password_hash('password', PASSWORD_BCRYPT, ['cost' => 4]);

        self::assertTrue($hasher->needsRehash($weak));
        self::assertFalse($hasher->needsRehash($hasher->hash('password')));
    }
}
