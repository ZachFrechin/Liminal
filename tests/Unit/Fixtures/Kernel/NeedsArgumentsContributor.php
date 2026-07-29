<?php

declare(strict_types=1);

namespace Liminal\Tests\Unit\Fixtures\Kernel;

use Liminal\Registry\Contract\Contributor;
use Liminal\Registry\RegistryCollection;

/**
 * Violates the manifest contract: contributors are plain-new instances, so a
 * required constructor argument must be refused at boot.
 */
final class NeedsArgumentsContributor implements Contributor
{
    public function __construct(private readonly string $dependency) {}

    public function contribute(RegistryCollection $registries): void
    {
        // Never reached: instantiation is refused first. The property exists
        // only to make the constructor argument genuinely required.
        assert($this->dependency !== '');
    }
}
