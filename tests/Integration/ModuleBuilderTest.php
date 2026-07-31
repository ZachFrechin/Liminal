<?php

declare(strict_types=1);

namespace Liminal\Tests\Integration;

use FilesystemIterator;
use Liminal\Kernel;
use Liminal\Lib\Module\Console\CreateCommand;
use Liminal\Lib\Module\Scaffold\ModuleScaffolder;
use Liminal\Registry\HookRegistry;
use Liminal\Registry\PermissionRegistry;
use Liminal\Registry\RouteRegistry;
use Liminal\Registry\TriggerRegistry;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The builder's contract, held by the repo's own toolchain: a generated
 * module BOOTS (every kernel assertion — slug grammar, route prefixes,
 * migration namespace agreement — runs against it) and its code passes the
 * SAME PHPStan level-max and PER-CS rules the tree lives under, with zero
 * edits. Needs no database: boot never touches one, and the tools read
 * files.
 */
#[CoversNothing]
final class ModuleBuilderTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../..';

    private string $temp;

    protected function setUp(): void
    {
        $created = sys_get_temp_dir() . '/liminal-builder-' . bin2hex(random_bytes(6));
        mkdir($created . '/config', 0o775, true);
        mkdir($created . '/modules', 0o775, true);

        // realpath, load-bearing on macOS: /var is a symlink to /private/var,
        // and if the autoload shim requires a class through the symlinked
        // path while PHPStan analyses the resolved one, the class carries two
        // file identities — PHPStan falls back to native reflection, loses
        // every method body, and reports 48 phantom "property only written"
        // errors.
        $this->temp = (string) realpath($created);
    }

    protected function tearDown(): void
    {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->temp, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($files as $file) {
            if ($file instanceof SplFileInfo) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
        }

        rmdir($this->temp);
    }

    public function testTheGeneratedModuleBootsAndPassesTheTreesOwnTools(): void
    {
        $written = new ModuleScaffolder()->scaffold($this->temp . '/modules', 'bookshelf', 'building-2', date('YmdHis'));
        self::assertCount(15, $written);

        // A five-line PSR-4 shim stands in for composer's autoloader.
        $modules = $this->temp . '/modules';
        spl_autoload_register(static function (string $class) use ($modules): void {
            if (str_starts_with($class, 'Liminal\Module\Bookshelf\\')) {
                $path = $modules . '/Bookshelf/' . str_replace('\\', '/', substr($class, strlen('Liminal\Module\Bookshelf\\'))) . '.php';

                if (is_file($path)) {
                    require $path;
                }
            }
        });

        // Boot a kernel that declares the generated module: every boot
        // assertion runs against the generated code.
        $this->writeTempRoot();
        $kernel = new Kernel($this->temp);
        $kernel->boot();

        $registries = $kernel->registries();
        self::assertNotNull($registries->get(RouteRegistry::class)->named('bookshelf.list'));
        self::assertNotNull($registries->get(RouteRegistry::class)->named('bookshelf.delete'));
        self::assertTrue($registries->get(HookRegistry::class)->has('bookshelf.deletion.veto'));
        self::assertContains('BOOKSHELF_CREATED', $registries->get(TriggerRegistry::class)->names());
        self::assertTrue($registries->get(PermissionRegistry::class)->has('bookshelf.read'));

        // The tree's own tools, on the generated directory, verbatim rules.
        // The autoload file mirrors composer's PSR-4 for the generated
        // namespace: the doctrine extension reflects entity attributes
        // through the CLI process's autoloader, exactly as it would once the
        // module lives under modules/ for real. Built with var_export — an
        // escaped heredoc once mangled the prefix and produced 48 phantom
        // property errors.
        file_put_contents($this->temp . '/autoload.php', sprintf(
            '<?php
            spl_autoload_register(static function (string $class): void {
                $prefix = %s;
                if (str_starts_with($class, $prefix)) {
                    $path = %s . str_replace(%s, \'/\', substr($class, strlen($prefix))) . \'.php\';
                    if (is_file($path)) {
                        require $path;
                    }
                }
            });',
            var_export('Liminal\Module\Bookshelf\\', true),
            var_export($modules . '/Bookshelf/', true),
            var_export('\\', true),
        ));

        $target = escapeshellarg($modules . '/Bookshelf');
        $root = escapeshellarg((string) realpath(self::ROOT));
        $autoload = escapeshellarg($this->temp . '/autoload.php');

        exec(
            sprintf('cd %s && php vendor/bin/phpstan analyse -c phpstan.neon --no-progress --autoload-file=%s %s 2>&1', $root, $autoload, $target),
            $stanOutput,
            $stanCode,
        );
        self::assertSame(0, $stanCode, "PHPStan on the generated module:\n" . implode("\n", $stanOutput));

        exec(
            sprintf('cd %s && php vendor/bin/php-cs-fixer fix %s --dry-run --config=.php-cs-fixer.dist.php 2>&1', $root, $target),
            $fixerOutput,
            $fixerCode,
        );
        self::assertSame(0, $fixerCode, "php-cs-fixer on the generated module:\n" . implode("\n", $fixerOutput));
    }

    public function testTheCommandRefusesWhatWouldCollide(): void
    {
        $command = new Kernel(self::ROOT)->container()->get(CreateCommand::class);
        self::assertInstanceOf(Command::class, $command);

        // A declared module name refuses before any write.
        $declared = new CommandTester($command);
        self::assertSame(Command::INVALID, $declared->execute(['name' => 'order']));
        self::assertStringContainsString('already declared', $declared->getDisplay());

        // The boot grammar refuses what the boot would refuse.
        $badSlug = new CommandTester($command);
        self::assertSame(Command::INVALID, $badSlug->execute(['name' => 'Bookshelf']));
        self::assertStringContainsString('not a slug', $badSlug->getDisplay());
    }

    private function writeTempRoot(): void
    {
        file_put_contents($this->temp . '/config/app.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            return [
                'name' => 'BuilderProof',
                'debug' => true,
                'compile' => false,
                'cache_dir' => sys_get_temp_dir() . '/liminal-builder-proof/cache',
                'log_dir' => sys_get_temp_dir() . '/liminal-builder-proof/log',
                'libs' => [
                    Liminal\Lib\Database\DatabaseContributor::class,
                    Liminal\Lib\Module\ModuleContributor::class,
                    Liminal\Lib\System\SystemContributor::class,
                ],
                'modules' => [
                    Liminal\Module\Bookshelf\BookshelfModule::class,
                ],
            ];
            PHP);

        file_put_contents($this->temp . '/config/database.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            return [
                'url' => null,
                'bootstrap_company_id' => 1,
            ];
            PHP);

        file_put_contents($this->temp . '/config/security.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            return [
                'session' => [
                    'cookie' => 'liminal',
                    'idle_ttl_seconds' => 7200,
                    'absolute_ttl_seconds' => 43200,
                    'secure' => false,
                    'gc_percent' => 0,
                ],
            ];
            PHP);
    }
}
