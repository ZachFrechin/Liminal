<?php

declare(strict_types=1);

namespace Liminal\Tests\Unit\Rendering;

use Liminal\Lib\Rendering\Exception\RenderingException;
use Liminal\Lib\Rendering\Translator;
use Liminal\Registry\TranslationRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Translator::class)]
final class TranslatorTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/Fixtures';

    /**
     * Contribution order is merge order, so a module's catalogue overrides a
     * lib's keys — the same layering the container applies to definitions.
     */
    public function testALaterFileOverridesAnEarlierKey(): void
    {
        $registry = new TranslationRegistry();
        $registry->add('en', self::FIXTURES . '/lib.en.php');
        $registry->add('en', self::FIXTURES . '/module.en.php');

        self::assertSame('Overridden', $this->translator($registry)->trans('shared.key'));
    }

    public function testAMissingKeyFallsBackToEnglishThenToTheKeyItself(): void
    {
        $registry = new TranslationRegistry();
        $registry->add('en', self::FIXTURES . '/lib.en.php');
        $registry->add('fr', self::FIXTURES . '/lib.fr.php');

        $translator = $this->translator($registry, 'fr');

        self::assertSame('Bonjour', $translator->trans('greeting'));
        // Absent from fr, present in en.
        self::assertSame('Only in English', $translator->trans('english.only'));
        // Absent everywhere: the raw key is its own visible alarm.
        self::assertSame('nowhere.at.all', $translator->trans('nowhere.at.all'));
    }

    public function testPlaceholdersAreReplaced(): void
    {
        $registry = new TranslationRegistry();
        $registry->add('en', self::FIXTURES . '/lib.en.php');

        self::assertSame(
            'Hello Jack, you have 3 messages',
            $this->translator($registry)->trans('welcome', ['name' => 'Jack', 'count' => 3]),
        );
    }

    public function testACatalogueFileNotReturningStringsFailsLoud(): void
    {
        $registry = new TranslationRegistry();
        $registry->add('en', self::FIXTURES . '/broken.en.php');

        $this->expectException(RenderingException::class);
        $this->expectExceptionMessageMatches('/does not return a map of strings/');

        $this->translator($registry)->trans('anything');
    }

    public function testAMissingCatalogueFileFailsLoud(): void
    {
        $registry = new TranslationRegistry();
        $registry->add('en', self::FIXTURES . '/does-not-exist.php');

        $this->expectException(RenderingException::class);

        $this->translator($registry)->trans('anything');
    }

    private function translator(TranslationRegistry $registry, string $locale = 'en'): Translator
    {
        return new Translator($registry, $locale);
    }
}
