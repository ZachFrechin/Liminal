<?php

declare(strict_types=1);

namespace Liminal\Tests\Unit\Authentication;

use Liminal\Module\Authentication\Http\LoginRedirect;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The open-redirect guard on the login page. Everything refused falls back to
 * null (the caller's default) rather than erroring: an error page here would be
 * a UX cliff and a free oracle.
 */
#[CoversClass(LoginRedirect::class)]
final class LoginRedirectTest extends TestCase
{
    public function testASameOriginPathWithItsQuerySurvives(): void
    {
        self::assertSame('/invoices/42?tab=lines', new LoginRedirect()->resolve('/invoices/42?tab=lines'));
    }

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function hostileTargets(): iterable
    {
        yield 'absolute url' => ['https://evil.example/steal'];
        yield 'scheme relative' => ['//evil.example/steal'];
        // Browsers fold backslashes to slashes, so this reaches the address bar
        // as //evil.example.
        yield 'backslash' => ['/\\evil.example'];
        yield 'carriage return' => ["/ok\r\nSet-Cookie: liminal=stolen"];
        yield 'null byte' => ["/ok\0"];
        yield 'not a path' => ['invoices/42'];
        yield 'overlong' => ['/' . str_repeat('a', 2048)];
        yield 'empty' => [''];
        yield 'absent' => [null];
    }

    #[DataProvider('hostileTargets')]
    public function testAnUnusableTargetFallsBackToTheDefault(?string $target): void
    {
        self::assertNull(new LoginRedirect()->resolve($target));
    }
}
