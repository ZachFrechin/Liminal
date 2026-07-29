<?php

declare(strict_types=1);

namespace Liminal\Lib\Database\Settings;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Liminal\Lib\Database\Exception\SettingsException;
use Liminal\Lib\Database\Scope\CompanyContext;
use Liminal\Registry\Exception\UndeclaredSettingException;
use Liminal\Registry\SettingScope;
use Liminal\Registry\SettingsRegistry;
use LogicException;

/**
 * Reads and writes the setting VALUES the SettingsRegistry only declares.
 *
 * Values live JSON-encoded in core_setting.value; row identity is
 * (setting_key, company_key, user_key) through the STORED sentinel columns, so
 * one ON DUPLICATE KEY UPDATE is a race-free upsert. The scope always comes
 * from the declaration — callers cannot invent one — and reads let a company
 * row beat a global one, so an installation-wide value can serve as the
 * fallback under company-scoped keys.
 *
 * Never constructor-inject this service into console commands: it carries a
 * Connection, and the console resolves every command eagerly.
 */
final readonly class SettingsService
{
    public function __construct(
        private Connection $connection,
        private SettingsRegistry $settings,
        private CompanyContext $context,
    ) {}

    /**
     * Company beats global in one indexed query; no row at either scope means
     * the declared default. A stored null is a real value, distinct from "no
     * row" ('null' round-trips through the JSON encoding).
     *
     * @return string|int|float|bool|array<array-key, mixed>|null
     *
     * @throws UndeclaredSettingException when the key was never declared
     * @throws SettingsException          when the key is user-scoped (phase 3)
     */
    public function get(string $key): string|int|float|bool|array|null
    {
        $definition = $this->settings->definition($key);
        $this->assertSupported($definition->scope);

        $raw = $this->connection->fetchOne(
            'SELECT value FROM core_setting
             WHERE setting_key = ? AND user_key = 0 AND company_key IN (0, ?)
             ORDER BY company_key DESC
             LIMIT 1',
            [$key, $this->context->currentId()],
        );

        if ($raw === false) {
            return $definition->default;
        }

        if (!is_string($raw)) {
            // SQL NULL from a foreign write: treat as an explicit null value.
            return null;
        }

        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        if ($decoded !== null && !is_scalar($decoded) && !is_array($decoded)) {
            // Unreachable with assoc decoding; keeps the return union honest.
            throw new LogicException(sprintf('Setting "%s" decoded to an unexpected %s.', $key, get_debug_type($decoded)));
        }

        return $decoded;
    }

    /**
     * @param string|int|float|bool|array<array-key, mixed>|null $value
     *
     * @throws UndeclaredSettingException when the key was never declared
     * @throws SettingsException          when the key is user-scoped (phase 3)
     */
    public function set(string $key, string|int|float|bool|array|null $value): void
    {
        $definition = $this->settings->definition($key);
        $this->assertSupported($definition->scope);

        $companyId = $definition->scope === SettingScope::Company ? $this->context->currentId() : null;
        $now = new DateTimeImmutable()->format('Y-m-d H:i:s');

        // VALUES() is current MariaDB syntax (the MySQL 8 row-alias replacement
        // is MySQL-only). Insert sets scope and created_at; the update arm only
        // touches value and updated_at. The generated sentinel columns never
        // appear in the column list — the database computes them.
        $this->connection->executeStatement(
            'INSERT INTO core_setting (setting_key, value, scope, company_id, user_id, created_at, updated_at)
             VALUES (?, ?, ?, ?, NULL, ?, ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = VALUES(updated_at)',
            [
                $key,
                json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
                $definition->scope->value,
                $companyId,
                $now,
                $now,
            ],
        );
    }

    /**
     * @throws SettingsException when the declared scope is not usable yet
     */
    private function assertSupported(SettingScope $scope): void
    {
        if ($scope === SettingScope::User) {
            throw SettingsException::userScopeNotAvailable();
        }
    }
}
