<?php

declare(strict_types=1);

namespace Liminal\Tests\Unit\Support;

use Liminal\Support\Env;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Env::class)]
final class EnvTest extends TestCase
{
    private const KEY = 'LIMINAL_ENVTEST';

    protected function tearDown(): void
    {
        putenv(self::KEY);
    }

    public function testStringReadsTheVariableAndFallsBackWhenAbsentOrEmpty(): void
    {
        self::assertSame('fallback', Env::string(self::KEY, 'fallback'));

        putenv(self::KEY . '=value');
        self::assertSame('value', Env::string(self::KEY, 'fallback'));

        putenv(self::KEY . '=');
        self::assertSame('fallback', Env::string(self::KEY, 'fallback'));
    }

    public function testBoolAcceptsTheUsualTruthySpellings(): void
    {
        foreach (['1', 'true', 'TRUE', 'yes', 'on'] as $truthy) {
            putenv(self::KEY . '=' . $truthy);
            self::assertTrue(Env::bool(self::KEY, false), $truthy);
        }

        foreach (['0', 'false', 'off', 'no', 'banana'] as $falsy) {
            putenv(self::KEY . '=' . $falsy);
            self::assertFalse(Env::bool(self::KEY, true), $falsy);
        }

        putenv(self::KEY);
        self::assertTrue(Env::bool(self::KEY, true));
    }

    public function testIntParsesStrictlyAndFallsBackOnGarbage(): void
    {
        putenv(self::KEY . '=42');
        self::assertSame(42, Env::int(self::KEY, 7));

        putenv(self::KEY . '=-3');
        self::assertSame(-3, Env::int(self::KEY, 7));

        putenv(self::KEY . '=4x2');
        self::assertSame(7, Env::int(self::KEY, 7));

        putenv(self::KEY);
        self::assertSame(7, Env::int(self::KEY, 7));
    }

    public function testNullableStringTreatsAbsentAndEmptyAsNull(): void
    {
        self::assertNull(Env::nullableString(self::KEY));

        putenv(self::KEY . '=');
        self::assertNull(Env::nullableString(self::KEY));

        putenv(self::KEY . '=set');
        self::assertSame('set', Env::nullableString(self::KEY));
    }
}
