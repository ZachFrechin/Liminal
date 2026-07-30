<?php

declare(strict_types=1);

namespace Liminal\Lib\Hook;

/**
 * What a hook listener may know besides the travelling value: which hook is
 * running, and the parameters the dispatch site provided. Readonly, and PHP
 * array copy semantics mean a listener cannot mutate the caller's
 * parameters — only the VALUE travels.
 */
final readonly class HookContext
{
    /**
     * @param array<string, mixed> $parameters
     */
    public function __construct(
        public string $hook,
        public array $parameters,
    ) {}
}
