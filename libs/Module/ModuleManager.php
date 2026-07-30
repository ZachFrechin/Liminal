<?php

declare(strict_types=1);

namespace Liminal\Lib\Module;

use Closure;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Liminal\Lib\Database\Migration\MigrationRunner;
use Liminal\Lib\Module\Exception\ModuleException;
use Liminal\Registry\Contract\Module;
use Liminal\Registry\ModuleRegistry;

/**
 * The module lifecycle: install (migrate + record), enable/disable per
 * company, and the state overview. All state lives in core_module and
 * core_module_company; the declared shape lives in the ModuleRegistry.
 *
 * Load-bearing invariant: contribute() runs for EVERY declared module
 * regardless of installed/enabled state — the system's shape is global and
 * boot is company-agnostic. Enabled is pure per-company state that phase 3's
 * request middleware will consult (through isEnabled, the single chokepoint);
 * nothing may ever "optimize" boot by consulting it.
 *
 * The connection is deferred (console-eager-resolution rule) and every
 * enablement read goes through this class, so a per-request cache has exactly
 * one place to land later.
 */
final readonly class ModuleManager
{
    /**
     * The only state 2b writes. Future states ('disabled', 'broken',
     * 'uninstalled') are documented here instead of implemented: the machine
     * has one node until its transitions exist.
     */
    private const string STATE_INSTALLED = 'installed';

    /**
     * @param Closure(): Connection $connection deferred so a missing DSN fails at run time, not boot
     */
    public function __construct(
        private ModuleRegistry $modules,
        private MigrationRunner $migrations,
        private Closure $connection,
    ) {}

    /**
     * Install or upgrade: runs the module's own migration namespace (the
     * global migrate may already have created its tables — that is a normal
     * fresh install with zero executed migrations), then records the row.
     *
     * @throws ModuleException when the module is undeclared or the core tables are absent
     */
    public function install(string $name): InstallOutcome
    {
        $manifest = $this->manifest($name);
        $connection = ($this->connection)();
        $this->assertCoreTables($connection);

        $previous = $connection->fetchOne('SELECT version FROM core_module WHERE name = ?', [$name]);
        $previous = is_string($previous) ? $previous : null;

        $namespace = $manifest->migrationNamespace();
        $executed = $namespace === null ? [] : $this->migrations->migrateToLatest($namespace);

        // Insert sets state and installed_at; the update arm refreshes version
        // and state only — installed_at keeps the first install time, mirroring
        // the created_at/updated_at split in core_setting. state = VALUES(state)
        // is deliberate: reinstalling must clear a future 'broken' state.
        $connection->executeStatement(
            'INSERT INTO core_module (name, version, state, installed_at)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE version = VALUES(version), state = VALUES(state)',
            [$name, $manifest->version(), self::STATE_INSTALLED, new DateTimeImmutable()->format('Y-m-d H:i:s')],
        );

        return $previous === null
            ? InstallOutcome::installed($executed, $manifest->version())
            : InstallOutcome::reinstalled($executed, $previous, $manifest->version());
    }

    /**
     * @throws ModuleException when undeclared, not installed, or the company does not exist
     */
    public function enable(string $name, int $companyId): void
    {
        $this->setEnabled($name, $companyId, true);
    }

    /**
     * @throws ModuleException when undeclared, not installed, or the company does not exist
     */
    public function disable(string $name, int $companyId): void
    {
        $this->setEnabled($name, $companyId, false);
    }

    public function isEnabled(string $name, int $companyId): bool
    {
        // fetchOne returns the STRING '0' for a disabled row — falsy, but not
        // the no-row `false`; compare numerically, never truthily.
        $raw = ($this->connection)()->fetchOne(
            'SELECT mc.enabled
             FROM core_module_company mc
             JOIN core_module m ON m.id = mc.module_id
             WHERE m.name = ? AND mc.company_id = ?',
            [$name, $companyId],
        );

        return is_numeric($raw) && (int) $raw === 1;
    }

    /**
     * Enablement of every declared module for one company in a SINGLE query —
     * the menu's read, where isEnabled() per item would mean one query per
     * entry. The gate keeps isEnabled(): one route, one module.
     *
     * @return array<string, bool> complete over declared modules; a missing row reads false
     */
    public function enabledFor(int $companyId): array
    {
        $enabled = array_fill_keys(array_keys($this->modules->all()), false);

        $rows = ($this->connection)()->fetchAllAssociative(
            'SELECT m.name
             FROM core_module_company mc
             JOIN core_module m ON m.id = mc.module_id
             WHERE mc.company_id = ? AND mc.enabled = 1',
            [$companyId],
        );

        foreach ($rows as $row) {
            $name = $row['name'] ?? null;

            if (is_string($name) && array_key_exists($name, $enabled)) {
                $enabled[$name] = true;
            }
        }

        return $enabled;
    }

    /**
     * Declared modules in declaration order, then installed rows whose module
     * left app.modules — removing a declaration must not hide database state.
     *
     * @return list<ModuleOverview>
     *
     * @throws ModuleException when the core tables are absent
     */
    public function overview(): array
    {
        $connection = ($this->connection)();
        $this->assertCoreTables($connection);

        $installedVersions = [];

        foreach ($connection->fetchAllAssociative('SELECT name, version FROM core_module ORDER BY id') as $row) {
            if (is_string($row['name'] ?? null) && is_string($row['version'] ?? null)) {
                $installedVersions[$row['name']] = $row['version'];
            }
        }

        $enabledByModule = [];

        $enabledRows = $connection->fetchAllAssociative(
            'SELECT m.name, mc.company_id
             FROM core_module_company mc
             JOIN core_module m ON m.id = mc.module_id
             WHERE mc.enabled = 1
             ORDER BY mc.company_id',
        );

        foreach ($enabledRows as $row) {
            if (is_string($row['name'] ?? null) && is_numeric($row['company_id'] ?? null)) {
                $enabledByModule[$row['name']][] = (int) $row['company_id'];
            }
        }

        $overview = [];

        foreach ($this->modules->all() as $name => $manifest) {
            $overview[] = new ModuleOverview(
                $name,
                $manifest->version(),
                $installedVersions[$name] ?? null,
                $enabledByModule[$name] ?? [],
            );

            unset($installedVersions[$name]);
        }

        foreach ($installedVersions as $name => $version) {
            $overview[] = new ModuleOverview($name, null, $version, $enabledByModule[$name] ?? []);
        }

        return $overview;
    }

    /**
     * @throws ModuleException
     */
    private function setEnabled(string $name, int $companyId, bool $enabled): void
    {
        $this->manifest($name);
        $connection = ($this->connection)();
        $this->assertCoreTables($connection);

        $moduleId = $connection->fetchOne('SELECT id FROM core_module WHERE name = ?', [$name]);

        if (!is_numeric($moduleId)) {
            throw ModuleException::notInstalled($name);
        }

        // Pre-checked for the clean message (the MigrateCommand pattern); the
        // FK constraint remains the race-proof backstop.
        if ($connection->fetchOne('SELECT id FROM core_company WHERE id = ?', [$companyId]) === false) {
            throw ModuleException::unknownCompany($companyId);
        }

        // Disable writes enabled = 0 instead of deleting: the row is the audit
        // trail, and company deletion already cascades it away.
        $connection->executeStatement(
            'INSERT INTO core_module_company (module_id, company_id, enabled)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE enabled = VALUES(enabled)',
            [(int) $moduleId, $companyId, $enabled ? 1 : 0],
        );
    }

    /**
     * @throws ModuleException when the name was never declared
     */
    private function manifest(string $name): Module
    {
        if (!$this->modules->has($name)) {
            throw ModuleException::unknown($name, array_keys($this->modules->all()));
        }

        return $this->modules->get($name);
    }

    /**
     * @throws ModuleException when the instance was never installed
     */
    private function assertCoreTables(Connection $connection): void
    {
        if (!$connection->createSchemaManager()->tablesExist(['core_module'])) {
            throw ModuleException::coreTablesMissing();
        }
    }
}
