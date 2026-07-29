<?php

declare(strict_types=1);

namespace Liminal\Lib\Module;

/**
 * One module:list row. Either side may be missing: a declared module that was
 * never installed has no installedVersion, and an installed row whose module
 * left app.modules has no declaredVersion — both states are shown rather
 * than hidden.
 */
final readonly class ModuleOverview
{
    /**
     * @param list<int> $enabledCompanyIds
     */
    public function __construct(
        public string $name,
        public ?string $declaredVersion,
        public ?string $installedVersion,
        public array $enabledCompanyIds,
    ) {}
}
