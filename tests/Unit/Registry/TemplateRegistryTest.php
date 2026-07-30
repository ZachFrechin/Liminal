<?php

declare(strict_types=1);

namespace Liminal\Tests\Unit\Registry;

use InvalidArgumentException;
use Liminal\Registry\Exception\DuplicateContributionException;
use Liminal\Registry\Exception\FrozenRegistryException;
use Liminal\Registry\TemplateRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TemplateRegistry::class)]
final class TemplateRegistryTest extends TestCase
{
    public function testPathsAccumulatePerNamespace(): void
    {
        $registry = new TemplateRegistry();
        $registry->add('liminal', '/lib/templates');
        $registry->add('liminal', '/other/templates');

        self::assertCount(2, $registry->all()['liminal'] ?? []);
    }

    /**
     * Twig's loader tries paths in order and the FIRST hit wins — so serving
     * latest-contribution-first is what lets a module shadow a lib's template.
     */
    public function testLaterContributionsShadowEarlierOnesInLookupOrder(): void
    {
        $registry = new TemplateRegistry();
        $registry->add('liminal', '/lib/templates');
        $registry->add('liminal', '/module/templates');
        $registry->freeze();

        self::assertSame(['/module/templates', '/lib/templates'], $registry->all()['liminal'] ?? []);
    }

    public function testTheExactNamespaceAndPathPairIsRefused(): void
    {
        $registry = new TemplateRegistry();
        $registry->add('liminal', '/templates');

        $this->expectException(DuplicateContributionException::class);

        $registry->add('liminal', '/templates');
    }

    public function testANamespaceThatIsNotASlugIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TemplateRegistry()->add('Not A Slug', '/templates');
    }

    public function testAnEmptyPathListIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TemplateRegistry()->add('liminal');
    }

    public function testContributionAfterFreezeIsRefused(): void
    {
        $registry = new TemplateRegistry();
        $registry->freeze();

        $this->expectException(FrozenRegistryException::class);

        $registry->add('liminal', '/templates');
    }
}
