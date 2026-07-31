<?php

declare(strict_types=1);

namespace Liminal\Lib\Module\Scaffold;

use Liminal\Lib\Module\Exception\ModuleException;
use Liminal\Registry\Contract\Module;

/**
 * Renders the stub set into a new module directory — the first code in the
 * tree that writes files, and the promise the README made at birth: a module
 * only fills registries, which is exactly what makes it mechanically
 * emittable. The stubs are the thirdparty CRUD pattern with placeholders;
 * their rendered output must pass composer check verbatim, and an
 * integration test holds the toolchain to that.
 *
 * The scaffolder validates the slug against THE boot grammar
 * (Module::NAME_PATTERN — the same constant assertManifest reads) and
 * refuses an existing directory. It never touches config/app.php: declaring
 * the module is the operator's gesture, printed by the command, because a
 * generator that edits configuration would be a module touching the core.
 */
final readonly class ModuleScaffolder
{
    /** Stub file => rendered path inside the module directory. */
    private const array FILES = [
        'module.php.stub' => '{{Studly}}Module.php',
        'migration.php.stub' => 'Migrations/Version{{stamp}}.php',
        'entity.php.stub' => 'Entity/{{Studly}}.php',
        'exception.php.stub' => 'Exception/{{Studly}}ModuleException.php',
        'repository.php.stub' => 'Repository/{{Studly}}Repository.php',
        'list-handler.php.stub' => 'Http/{{Studly}}ListHandler.php',
        'detail-handler.php.stub' => 'Http/{{Studly}}DetailHandler.php',
        'create-page-handler.php.stub' => 'Http/{{Studly}}CreatePageHandler.php',
        'create-submit-handler.php.stub' => 'Http/{{Studly}}CreateSubmitHandler.php',
        'update-handler.php.stub' => 'Http/{{Studly}}UpdateHandler.php',
        'delete-handler.php.stub' => 'Http/{{Studly}}DeleteHandler.php',
        'list.html.twig.stub' => 'templates/{{slug}}s.html.twig',
        'detail.html.twig.stub' => 'templates/{{slug}}.html.twig',
        'create.html.twig.stub' => 'templates/{{slug}}_create.html.twig',
        'lang.php.stub' => 'lang/en.php',
    ];

    /**
     * Writes the module and returns the rendered file paths, relative to the
     * module directory. $stamp is the migration version (YmdHis), provided
     * by the caller so tests stay deterministic.
     *
     * @return list<string>
     *
     * @throws ModuleException when the slug is invalid or the directory exists
     */
    public function scaffold(string $modulesDir, string $slug, string $icon, string $stamp): array
    {
        if (preg_match(Module::NAME_PATTERN, $slug) !== 1 || strlen($slug) > 64) {
            throw ModuleException::invalidSlug($slug);
        }

        $studly = str_replace('_', '', ucwords($slug, '_'));
        $target = $modulesDir . '/' . $studly;

        if (is_dir($target)) {
            throw ModuleException::directoryExists($target);
        }

        $replacements = [
            '{{slug}}' => $slug,
            '{{Studly}}' => $studly,
            '{{SCREAMING}}' => strtoupper($slug),
            '{{stamp}}' => $stamp,
            '{{icon}}' => $icon,
        ];

        $written = [];

        foreach (self::FILES as $stub => $path) {
            $source = __DIR__ . '/../stubs/' . $stub;
            $content = file_get_contents($source);

            if ($content === false) {
                throw ModuleException::stubMissing($source);
            }

            $relative = strtr($path, $replacements);
            $absolute = $target . '/' . $relative;
            $directory = dirname($absolute);

            if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
                throw ModuleException::directoryNotWritable($directory);
            }

            file_put_contents($absolute, strtr($content, $replacements));
            $written[] = $relative;
        }

        return $written;
    }
}
