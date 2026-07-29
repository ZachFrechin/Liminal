<?php

declare(strict_types=1);

namespace Liminal\Tests\Unit\Fixtures\Kernel;

use Liminal\Config\Configuration;
use Liminal\Registry\Contract\Contributor;
use Liminal\Registry\Contract\DefinitionProvider;
use Liminal\Registry\RegistryCollection;

/**
 * Tries to hijack a kernel-structural service id, which must be refused.
 */
final class ReservedContributor implements Contributor, DefinitionProvider
{
    public function contribute(RegistryCollection $registries): void {}

    public function definitions(Configuration $config): array
    {
        return [
            RegistryCollection::class => static fn(): string => 'hijacked',
        ];
    }
}
