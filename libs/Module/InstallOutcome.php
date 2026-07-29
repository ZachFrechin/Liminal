<?php

declare(strict_types=1);

namespace Liminal\Lib\Module;

/**
 * Outcome of one ModuleManager::install(): what ran and how the stored
 * version moved. "No migrations executed" and "already installed" are
 * distinct facts — a fresh install may legitimately execute nothing when the
 * global migrate already created the module's tables.
 */
final readonly class InstallOutcome
{
    /**
     * @param list<string> $executedMigrations
     */
    private function __construct(
        public array $executedMigrations,
        public ?string $previousVersion,
        public string $version,
    ) {}

    /**
     * @param list<string> $executed
     */
    public static function installed(array $executed, string $version): self
    {
        return new self($executed, null, $version);
    }

    /**
     * @param list<string> $executed
     */
    public static function reinstalled(array $executed, string $previousVersion, string $version): self
    {
        return new self($executed, $previousVersion, $version);
    }

    public function wasFreshInstall(): bool
    {
        return $this->previousVersion === null;
    }

    /**
     * Plain inequality on verbatim versions — deliberately not semver: the
     * migrations are the real upgrade mechanism, the version is a label.
     */
    public function changedVersion(): bool
    {
        return $this->previousVersion !== null && $this->previousVersion !== $this->version;
    }
}
