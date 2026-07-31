<?php

declare(strict_types=1);

namespace Liminal\Tests\Unit\Module;

use FilesystemIterator;
use Liminal\Lib\Module\Exception\ModuleException;
use Liminal\Lib\Module\Scaffold\ModuleScaffolder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

#[CoversClass(ModuleScaffolder::class)]
final class ModuleScaffolderTest extends TestCase
{
    private string $target;

    protected function setUp(): void
    {
        $this->target = sys_get_temp_dir() . '/liminal-scaffold-' . bin2hex(random_bytes(6));
        mkdir($this->target, 0o775, true);
    }

    protected function tearDown(): void
    {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->target, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($files as $file) {
            if ($file instanceof SplFileInfo) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
        }

        rmdir($this->target);
    }

    public function testTheFullStubSetRendersWithNoPlaceholderLeftBehind(): void
    {
        $written = new ModuleScaffolder()->scaffold($this->target, 'bookshelf', 'building-2', '20260731999999');

        self::assertCount(15, $written);
        self::assertContains('BookshelfModule.php', $written);
        self::assertContains('Migrations/Version20260731999999.php', $written);
        self::assertContains('templates/bookshelfs.html.twig', $written);

        foreach ($written as $relative) {
            $content = (string) file_get_contents($this->target . '/Bookshelf/' . $relative);
            self::assertStringNotContainsString('{{slug}}', $content, $relative);
            self::assertStringNotContainsString('{{Studly}}', $content, $relative);
            self::assertStringNotContainsString('{{SCREAMING}}', $content, $relative);
            self::assertStringNotContainsString('{{stamp}}', $content, $relative);
            self::assertStringNotContainsString('{{icon}}', $content, $relative);
        }

        $manifest = (string) file_get_contents($this->target . '/Bookshelf/BookshelfModule.php');
        self::assertStringContainsString("public const string NAME = 'bookshelf';", $manifest);
        self::assertStringContainsString("declare('BOOKSHELF_CREATED')", $manifest);
        self::assertStringContainsString("'bookshelf.deletion.veto'", $manifest);
    }

    public function testAMultiWordSlugStudliesCorrectly(): void
    {
        $written = new ModuleScaffolder()->scaffold($this->target, 'book_shelf', 'building-2', '20260731999999');

        self::assertContains('BookShelfModule.php', $written);
        $entity = (string) file_get_contents($this->target . '/BookShelf/Entity/BookShelf.php');
        self::assertStringContainsString('class BookShelf implements CompanyScoped', $entity);
        self::assertStringContainsString("name: 'book_shelf_book_shelf'", $entity);
    }

    public function testTheBootGrammarRefusesWhatTheBootWouldRefuse(): void
    {
        $this->expectException(ModuleException::class);

        new ModuleScaffolder()->scaffold($this->target, 'Bookshelf', 'building-2', '20260731999999');
    }

    public function testAnExistingDirectoryIsNeverOverwritten(): void
    {
        mkdir($this->target . '/Bookshelf');

        $this->expectException(ModuleException::class);

        new ModuleScaffolder()->scaffold($this->target, 'bookshelf', 'building-2', '20260731999999');
    }
}
