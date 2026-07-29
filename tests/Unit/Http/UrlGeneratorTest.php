<?php

declare(strict_types=1);

namespace Liminal\Tests\Unit\Http;

use Liminal\Http\Exception\UrlGenerationException;
use Liminal\Http\UrlGenerator;
use Liminal\Registry\RouteRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(UrlGenerator::class)]
final class UrlGeneratorTest extends TestCase
{
    public function testAStaticPathComesBackVerbatim(): void
    {
        self::assertSame('/companies', $this->generator()->generate('company.list'));
    }

    public function testPlaceholdersAreSubstitutedAndEncoded(): void
    {
        self::assertSame(
            '/companies/42/rename/a%20b',
            $this->generator()->generate('company.rename', ['id' => 42, 'slug' => 'a b']),
        );
    }

    public function testRegexConstrainedPlaceholdersAreSubstitutedToo(): void
    {
        self::assertSame('/invoices/2026', $this->generator()->generate('invoice.year', ['year' => 2026]));
    }

    public function testLeftoverParametersBecomeTheQueryString(): void
    {
        self::assertSame(
            '/companies/42?tab=users&page=2',
            $this->generator()->generate('company.show', ['id' => 42, 'tab' => 'users', 'page' => 2]),
        );
    }

    public function testAnUnknownNameIsRefused(): void
    {
        $this->expectException(UrlGenerationException::class);
        $this->expectExceptionMessageMatches('/No route is named/');

        $this->generator()->generate('nope.nope');
    }

    public function testAMissingPlaceholderValueIsRefused(): void
    {
        $this->expectException(UrlGenerationException::class);
        $this->expectExceptionMessageMatches('/needs a value for parameter "id"/');

        $this->generator()->generate('company.show');
    }

    public function testOptionalSegmentsAreRefusedForNow(): void
    {
        $this->expectException(UrlGenerationException::class);
        $this->expectExceptionMessageMatches('/optional segments/');

        $this->generator()->generate('report.optional');
    }

    private function generator(): UrlGenerator
    {
        $routes = new RouteRegistry();
        $routes->get('/companies', 'Handler', 'company.list');
        $routes->get('/companies/{id}', 'Handler', 'company.show');
        $routes->get('/companies/{id}/rename/{slug}', 'Handler', 'company.rename');
        $routes->get('/invoices/{year:\d{4}}', 'Handler', 'invoice.year');
        $routes->get('/reports[/{name}]', 'Handler', 'report.optional');

        return new UrlGenerator($routes);
    }
}
