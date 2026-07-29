<?php

declare(strict_types=1);

namespace Liminal\Tests\Unit\Fixtures\Kernel;

use Liminal\Config\Configuration;
use Liminal\Registry\Contract\Contributor;
use Liminal\Registry\Contract\DefinitionProvider;
use Liminal\Registry\RegistryCollection;

/**
 * The happy path: a lib exposing one lazy service through definitions().
 */
final class ProvidingContributor implements Contributor, DefinitionProvider
{
    public function contribute(RegistryCollection $registries): void {}

    public function definitions(Configuration $config): array
    {
        return [
            'fixture.greeting' => static fn(): string => 'hello from lib',
        ];
    }
}
