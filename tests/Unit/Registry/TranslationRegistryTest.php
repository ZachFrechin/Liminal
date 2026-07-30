<?php

declare(strict_types=1);

namespace Liminal\Tests\Unit\Registry;

use InvalidArgumentException;
use Liminal\Registry\Exception\DuplicateContributionException;
use Liminal\Registry\Exception\FrozenRegistryException;
use Liminal\Registry\TranslationRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TranslationRegistry::class)]
final class TranslationRegistryTest extends TestCase
{
    /**
     * Contribution order is the merge order: the Translator applies files
     * last-wins, so a module's catalogue overrides a lib's keys.
     */
    public function testFilesAccumulatePerLocaleInContributionOrder(): void
    {
        $registry = new TranslationRegistry();
        $registry->add('en', '/lib/lang/en.php');
        $registry->add('en', '/module/lang/en.php');
        $registry->add('fr', '/lib/lang/fr.php');

        self::assertSame([
            'en' => ['/lib/lang/en.php', '/module/lang/en.php'],
            'fr' => ['/lib/lang/fr.php'],
        ], $registry->all());
    }

    public function testTheExactLocaleAndFilePairIsRefused(): void
    {
        $registry = new TranslationRegistry();
        $registry->add('en', '/lang/en.php');

        $this->expectException(DuplicateContributionException::class);

        $registry->add('en', '/lang/en.php');
    }

    public function testAMalformedLocaleIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        // 'EN' would silently fork the 'en' catalogue.
        new TranslationRegistry()->add('EN', '/lang/en.php');
    }

    public function testContributionAfterFreezeIsRefused(): void
    {
        $registry = new TranslationRegistry();
        $registry->freeze();

        $this->expectException(FrozenRegistryException::class);

        $registry->add('en', '/lang/en.php');
    }
}
