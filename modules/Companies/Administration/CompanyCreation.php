<?php

declare(strict_types=1);

namespace Liminal\Module\Companies\Administration;

/**
 * What creating a company produced: its id, the modules switched on for it,
 * and the declared modules that could NOT be — installed nowhere, so the
 * screen can tell the operator to run `module:install` instead of leaving
 * 404s unexplained.
 */
final readonly class CompanyCreation
{
    /**
     * @param list<string> $enabledModules
     * @param list<string> $notInstalled
     */
    public function __construct(
        public int $companyId,
        public array $enabledModules,
        public array $notInstalled,
    ) {}
}
