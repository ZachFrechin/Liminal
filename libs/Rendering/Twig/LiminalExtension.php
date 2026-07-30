<?php

declare(strict_types=1);

namespace Liminal\Lib\Rendering\Twig;

use Liminal\Http\UrlGenerator;
use Liminal\Lib\Database\Scope\CompanyContext;
use Liminal\Lib\Database\Scope\CompanyDirectory;
use Liminal\Lib\Module\ModuleManager;
use Liminal\Lib\Rendering\Exception\RenderingException;
use Liminal\Lib\Rendering\Icon\IconSet;
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
        private readonly IconSet $icons,
        private readonly CompanyDirectory $companies,
        private readonly CompanyContext $companyContext,
        private readonly ModuleManager $modules,
    ) {}

    /**
     * @return list<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('url', $this->url(...)),
            new TwigFunction('asset', $this->asset(...)),
            new TwigFunction('csrf_token', $this->csrfToken(...)),
            new TwigFunction('csrf_field', $this->csrfField(...), ['is_safe' => ['html']]),
            new TwigFunction('flash', $this->flash(...)),
            new TwigFunction('current_user', $this->currentUser->get(...)),
            new TwigFunction('menu', $this->menu->build(...)),
            // Safe like csrf_field: the markup is a code constant, never data.
            new TwigFunction('icon', $this->icons->svg(...), ['is_safe' => ['html']]),
            new TwigFunction('accessible_companies', $this->accessibleCompanies(...)),
            new TwigFunction('company_switching', $this->companySwitching(...)),
            new TwigFunction('route_exists', $this->urls->has(...)),
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

    private function asset(string $path): string
    {
        // Static files live under the public docroot; the webserver serves
        // them without ever reaching the kernel. The prefix is fixed because
        // the application already assumes a root mount everywhere URLs are
        // made — this method is the single seam if that ever changes.
        return '/assets/' . ltrim($path, '/');
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

    /**
     * The shell's switcher data: the companies this user reaches, by name,
     * the working one flagged. Anonymous short-circuits BEFORE any connection
     * contact — a DSN-less checkout must still serve its public pages, the
     * MenuBuilder precedent. One bounded IN-query per render, menu parity.
     *
     * @return list<array{id: int, code: string, name: string, current: bool}>
     */
    private function accessibleCompanies(): array
    {
        if ($this->currentUser->get() === null) {
            return [];
        }

        $current = $this->companyContext->currentId();

        return array_map(
            fn(array $company): array => [...$company, 'current' => $company['id'] === $current],
            $this->companies->byIds($this->companyContext->accessibleIds()),
        );
    }

    /**
     * Whether the shell may render switch forms. The switch route belongs to
     * the authentication module: disabled for the working company, its POST
     * answers 404 — advertising forms that cannot land is the one lie the
     * shell could tell. Naming the module here is presentation knowledge of
     * the admin reference module, and only evaluates where its routes exist.
     */
    private function companySwitching(): bool
    {
        return $this->currentUser->get() !== null
            && $this->urls->has('authentication.switch')
            && $this->modules->isEnabled('authentication', $this->companyContext->currentId());
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
