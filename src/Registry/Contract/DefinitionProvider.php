<?php

declare(strict_types=1);

namespace Liminal\Registry\Contract;

use Liminal\Config\Configuration;

/**
 * Optional companion to Contributor for libs (and, in phase 2, modules) that
 * need services in the container.
 *
 * definitions() runs BEFORE the container is built — that is the whole point:
 * it is the only moment definitions can still be added, since a built PHP-DI
 * container is immutable. Two consequences follow. Contributors are
 * instantiated with plain `new`, so they must not require constructor
 * arguments; and every definition must stay lazy (a closure or a PHP-DI
 * definition object) — nothing here may touch I/O or build real services.
 *
 * Kernel-structural ids (Configuration, RegistryCollection, the registries)
 * are reserved and may not be redefined. Everything else layers last-wins:
 * kernel defaults, then libs in app.libs order, then modules — a lib may
 * replace LoggerInterface; the registries, never.
 */
interface DefinitionProvider
{
    /**
     * @return array<string, mixed> PHP-DI definitions keyed by service id
     */
    public function definitions(Configuration $config): array;
}
