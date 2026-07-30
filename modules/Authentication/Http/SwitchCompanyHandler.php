<?php

declare(strict_types=1);

namespace Liminal\Module\Authentication\Http;

use Liminal\Http\UrlGenerator;
use Liminal\Lib\Security\Authentication\CurrentUser;
use Liminal\Lib\Security\Session\Session;
use Liminal\Lib\Security\Session\SessionMiddleware;
use Liminal\Module\Authentication\Exception\AuthenticationModuleException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The first writer of the session's company preference on behalf of an actual
 * choice — everything before it only echoed validated state back.
 *
 * The handler stores the preference and redirects; it deliberately does NOT
 * call CompanyContext::switchTo(). Nothing consults the scope on the response
 * path of a 302, and switchTo() would fire the onSwitch listeners — clearing
 * the EntityManager mid-request for nobody's benefit. The switch middleware
 * revalidates and applies the preference on the very next request, exactly as
 * it does for every request.
 *
 * An inaccessible target is refused with a flash rather than the middleware's
 * silent fallback: an explicit click deserves an explicit answer. The
 * validation happens again at 300 anyway — this check is UX, that one is law.
 *
 * Recorded trap (Known gaps): if this module is disabled for the CURRENT
 * company, this route and /account both 404 and the user cannot switch away
 * from it in the browser. The console escape is
 * `module:enable authentication <company>`. Hosting the route in the security
 * lib would not help while the only form lives on /account — and error pages
 * can never carry a form.
 */
final readonly class SwitchCompanyHandler implements RequestHandlerInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private UrlGenerator $urls,
        private ResponseFactoryInterface $responses,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $session = $request->getAttribute(SessionMiddleware::ATTRIBUTE);

        if (!$session instanceof Session) {
            throw AuthenticationModuleException::sessionMissing();
        }

        $body = $request->getParsedBody();
        $raw = is_array($body) ? ($body['company'] ?? null) : null;
        $wanted = is_numeric($raw) ? (int) $raw : 0;

        $accessible = $this->currentUser->get()?->accessibleCompanyIds() ?? [];

        if (!in_array($wanted, $accessible, true)) {
            $session->set('error', 'authentication.switch.refused');

            return $this->backToAccount();
        }

        $session->setCompanyId($wanted);
        $session->set('success', 'authentication.switch.done');

        return $this->backToAccount();
    }

    private function backToAccount(): ResponseInterface
    {
        return $this->responses->createResponse(302)
            ->withHeader('Location', $this->urls->generate('authentication.account'));
    }
}
