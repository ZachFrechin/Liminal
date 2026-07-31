<?php

declare(strict_types=1);

namespace Liminal\Registry\Contract;

/**
 * A module's manifest: the identity the module system stores and the lifecycle
 * keys it needs, on top of the Contributor surface every lib already has.
 *
 * Manifests are stateless and I/O-free: name(), version() and
 * migrationNamespace() must return constants — they are called during boot and
 * printed by diagnostics. name() is the module's identity everywhere:
 * core_module.name, the module:* command argument, and (convention, enforced in
 * phase 3) the prefix of the module's route names, as in "system.health".
 */
interface Module extends Contributor
{
    /**
     * THE slug grammar, in one place: the boot's assertManifest and the
     * module builder validate against this same constant — two copies would
     * be two grammars the day one of them drifts.
     */
    public const string NAME_PATTERN = '/^[a-z][a-z0-9]*(?:_[a-z0-9]+)*$/';

    /**
     * Unique lowercase slug ([a-z][a-z0-9_]*, 64 characters at most),
     * e.g. 'authentication'.
     */
    public function name(): string;

    /**
     * Manifest version stored verbatim in core_module.version (32 characters
     * at most).
     */
    public function version(): string;

    /**
     * The migration namespace this module also registers in contribute(), or
     * null when it has none; module:install migrates exactly this namespace.
     */
    public function migrationNamespace(): ?string;
}
