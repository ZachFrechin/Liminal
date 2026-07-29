<?php

declare(strict_types=1);

namespace Liminal\Registry;

use Liminal\Registry\Exception\DuplicateContributionException;
use Psr\Http\Server\MiddlewareInterface;

/**
 * The HTTP pipeline as a contribution surface: libs and modules insert their
 * middleware between the kernel's anchors instead of the kernel hardcoding a
 * list.
 *
 * LOWER priority runs EARLIER — i.e. more to the OUTSIDE of the pipeline. The
 * ascending sort IS the Pipeline array, so all() reads in request-flow order.
 * (Symfony-trained readers beware: their event listeners use the opposite
 * convention.) Ties keep registration order (usort is stable), and the kernel
 * registers its anchors before any contributor runs, so an anchor wins every
 * tie. Suggested bands: -999..-1 before routing (sessions ~ -900, CORS ~ -800),
 * 1..999 after routing (auth ~ 100, CSRF ~ 200, company switch ~ 300 — they
 * need the matched route). The kernel refuses at boot anything sorted outside
 * the error handler or behind the dispatcher.
 */
final class MiddlewareRegistry extends AbstractRegistry
{
    public const int ERROR_HANDLER = -1000;

    public const int ROUTER = 0;

    public const int DISPATCH = 1000;

    /** @var list<array{class: class-string<MiddlewareInterface>, priority: int}> */
    private array $entries = [];

    /** @var array<string, true> */
    private array $classes = [];

    /**
     * @param class-string<MiddlewareInterface> $middleware service id resolved through the container
     *
     * @throws DuplicateContributionException when the middleware class is already registered
     */
    public function add(string $middleware, int $priority): void
    {
        $this->assertMutable();

        if (isset($this->classes[$middleware])) {
            throw DuplicateContributionException::for(static::class, $middleware);
        }

        $this->classes[$middleware] = true;
        $this->entries[] = ['class' => $middleware, 'priority' => $priority];
    }

    /** @return list<class-string<MiddlewareInterface>> outermost first — feeds the Pipeline verbatim */
    public function all(): array
    {
        return array_column(
            $this->isFrozen() ? $this->entries : $this->sorted($this->entries),
            'class',
        );
    }

    protected function onFreeze(): void
    {
        $this->entries = $this->sorted($this->entries);
    }

    /**
     * @param list<array{class: class-string<MiddlewareInterface>, priority: int}> $entries
     *
     * @return list<array{class: class-string<MiddlewareInterface>, priority: int}>
     */
    private function sorted(array $entries): array
    {
        usort($entries, static fn(array $a, array $b): int => $a['priority'] <=> $b['priority']);

        return $entries;
    }
}
