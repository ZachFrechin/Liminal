<?php

declare(strict_types=1);

namespace Liminal\Config;

use Liminal\Config\Exception\MissingConfigurationException;

/**
 * Read-only dot-notation view over the merged config files.
 *
 * Typed accessors keep PHPStan honest at the boundary where untyped array data
 * enters the application.
 */
final readonly class Configuration
{
    /** @param array<string, mixed> $values */
    public function __construct(private array $values) {}

    public function has(string $key): bool
    {
        return $this->find($key) !== null;
    }

    /**
     * @throws MissingConfigurationException when the key is absent or mistyped and no default was given
     */
    public function string(string $key, ?string $default = null): string
    {
        $value = $this->find($key);

        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return $default ?? throw MissingConfigurationException::for($key, 'string');
    }

    /**
     * @throws MissingConfigurationException when the key is absent or mistyped and no default was given
     */
    public function bool(string $key, ?bool $default = null): bool
    {
        $value = $this->find($key);

        if (is_bool($value)) {
            return $value;
        }

        return $default ?? throw MissingConfigurationException::for($key, 'bool');
    }

    /**
     * @throws MissingConfigurationException when the key is absent or mistyped and no default was given
     */
    public function int(string $key, ?int $default = null): int
    {
        $value = $this->find($key);

        if (is_int($value)) {
            return $value;
        }

        return $default ?? throw MissingConfigurationException::for($key, 'int');
    }

    /**
     * An absent key is an empty list; a present key with anything but a list
     * of strings throws. Filtering bad entries silently would make a mistyped
     * lib class in app.libs simply vanish from the boot.
     *
     * @return list<string>
     *
     * @throws MissingConfigurationException when the value is not a list of strings
     */
    public function stringList(string $key): array
    {
        $value = $this->find($key);

        if ($value === null) {
            return [];
        }

        if (!is_array($value)) {
            throw MissingConfigurationException::for($key, 'list of strings');
        }

        $list = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                throw MissingConfigurationException::for($key, 'list of strings');
            }

            $list[] = $item;
        }

        return $list;
    }

    private function find(string $key): mixed
    {
        $current = $this->values;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }

            $current = $current[$segment];
        }

        return $current;
    }
}
