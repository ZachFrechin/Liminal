<?php

declare(strict_types=1);

namespace Liminal\Registry;

use Liminal\Registry\Exception\DuplicateContributionException;
use Liminal\Registry\Exception\UndeclaredSettingException;

/**
 * Typed configuration keys with defaults and a scope.
 *
 * Reads go through has()/definition() so a module cannot consume a setting it did
 * not declare — the first of the registry-enforced boundaries the module trust
 * model relies on.
 */
final class SettingsRegistry extends AbstractRegistry
{
    /** @var array<string, SettingDefinition> */
    private array $settings = [];

    /**
     * @throws DuplicateContributionException when the setting key is already declared
     */
    public function add(SettingDefinition $definition): void
    {
        $this->assertMutable();

        if (isset($this->settings[$definition->key])) {
            throw DuplicateContributionException::for(static::class, $definition->key);
        }

        $this->settings[$definition->key] = $definition;
    }

    public function has(string $key): bool
    {
        return isset($this->settings[$key]);
    }

    /**
     * @throws UndeclaredSettingException when the key was never declared
     */
    public function definition(string $key): SettingDefinition
    {
        return $this->settings[$key] ?? throw UndeclaredSettingException::for($key);
    }

    /** @return array<string, SettingDefinition> */
    public function all(): array
    {
        return $this->settings;
    }
}
