<?php

declare(strict_types=1);

namespace Liminal\Tests\Unit\Invoice;

use InvalidArgumentException;
use Liminal\Module\Invoice\Money\Cents;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Cents::class)]
final class CentsTest extends TestCase
{
    public function testDecimalStringsBecomeCentsWithoutFloats(): void
    {
        self::assertSame(123456, Cents::fromDecimal('1234.56'));
        self::assertSame(123456, Cents::fromDecimal('1234,56'));
        self::assertSame(123400, Cents::fromDecimal('1234'));
        self::assertSame(150, Cents::fromDecimal('1.5'));
        self::assertSame(0, Cents::fromDecimal('0.00'));
        // The classic float trap: 19.99 is not representable in binary.
        self::assertSame(1999, Cents::fromDecimal('19.99'));
    }

    public function testCentsRenderBackAsTwoDecimalStrings(): void
    {
        self::assertSame('1234.56', Cents::toDecimal(123456));
        self::assertSame('0.05', Cents::toDecimal(5));
        self::assertSame('0.00', Cents::toDecimal(0));
    }

    public function testMalformedStringsAreWiringAndThrow(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Cents::fromDecimal('12.345');
    }

    public function testNegativeAmountsAreRefused(): void
    {
        // Nothing in the module produces one; a minus sign reaching here
        // means a form validator went missing.
        $this->expectException(InvalidArgumentException::class);

        Cents::fromDecimal('-5.00');
    }
}
