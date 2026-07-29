<?php

declare(strict_types=1);

namespace Liminal\Lib\Database\Install;

/**
 * Outcome of one FirstCompanySeeder::seed(): either the id of the company it
 * created, or how many rows already existed — never both.
 */
final readonly class SeedResult
{
    private function __construct(
        public ?int $companyId,
        public int $existing,
    ) {}

    public static function seeded(int $companyId): self
    {
        return new self($companyId, 0);
    }

    public static function skipped(int $existing): self
    {
        return new self(null, $existing);
    }

    public function wasSeeded(): bool
    {
        return $this->companyId !== null;
    }
}
