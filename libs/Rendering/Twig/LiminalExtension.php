<?php

declare(strict_types=1);

namespace Liminal\Lib\Rendering\Twig;

use Liminal\Http\UrlGenerator;
use Liminal\Lib\Rendering\Exception\RenderingException;
use Liminal\Lib\Rendering\Menu\MenuBuilder;
use Liminal\Lib\Rendering\Translator;
use Liminal\Lib\Rendering\View\ViewContext;
use Liminal\Lib\Security\Authentication\CurrentUser;
use Liminal\Lib\Security\Csrf\CsrfMiddleware;
use Liminal\Lib\Security\Csrf\CsrfTokenManager;
use Liminal\Lib\Security\Session\Session;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * The view helpers — one extension, a facade by nature. All per-request state
 * arrives through the holders (ViewContext, CurrentUser); nothing here needs
 * the Twig context or environment.
 *
 * Failure asymmetry, on purpose: flash() tolerates a missing session (an
 * absent flash is the normal case of nearly every request — content), while
 * csrf_token() refuses one (a form without a real token means every later
 * POST fails 403 with nothing to see — wiring).
 */
final class LiminalExtension extends AbstractExtension
{
    public function __construct(
        private readonly UrlGenerator $urls,
        private readonly CsrfTokenManager $csrf,
        private readonly ViewContext $viewContext,
        private readonly CurrentUser $currentUser,
        private readonly MenuBuilder $menu,
        private readonly Translator $translator,
    ) {}

    /**
     * @return list<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('url', $this->url(...)),
            new TwigFunction('csrf_token', $this->csrfToken(...)),
            new TwigFunction('csrf_field', $this->csrfField(...), ['is_safe' => ['html']]),
            new TwigFunction('flash', $this->flash(...)),
            new TwigFunction('current_user', $this->currentUser->get(...)),
            new TwigFunction('menu', $this->menu->build(...)),
        ];
    }

    /**
     * @return list<TwigFilter>
     */
    public function getFilters(): array
    {
        return [
            new TwigFilter('trans', $this->translator->trans(...)),
        ];
    }

    /**
     * @param array<string, string|int|float> $parameters
     */
    private function url(string $name, array $parameters = []): string
    {
        // A template naming a dead route is wiring: the exception propagates.
        return $this->urls->generate($name, $parameters);
    }

    private function csrfToken(): string
    {
        // The lazy token write is what organically creates the session row and
        // cookie on the first form render — by design.
        return $this->csrf->token($this->session('csrf_token'));
    }

    private function csrfField(): string
    {
        return sprintf(
            '<input type="hidden" name="%s" value="%s">',
            CsrfMiddleware::FIELD,
            // Defensive: the token is base64url, but escaping is not optional.
            htmlspecialchars($this->csrfToken(), ENT_QUOTES),
        );
    }

    private function flash(string $key): ?string
    {
        $value = $this->viewContext->get()?->pull($key);

        return is_string($value) ? $value : null;
    }

    /**
     * @throws RenderingException when no session is assigned for this request
     */
    private function session(string $function): Session
    {
        return $this->viewContext->get() ?? throw RenderingException::sessionUnavailable($function);
    }
}
