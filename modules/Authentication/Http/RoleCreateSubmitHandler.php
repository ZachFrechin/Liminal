<?php

declare(strict_types=1);

namespace Liminal\Module\Authentication\Http;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Liminal\Http\UrlGenerator;
use Liminal\Lib\Hook\Triggers;
use Liminal\Lib\Security\Authorization\RequestGate;
use Liminal\Lib\Security\Session\Session;
use Liminal\Lib\Security\Session\SessionMiddleware;
use Liminal\Module\Authentication\Administration\UserAdministration;
use Liminal\Module\Authentication\Exception\AuthenticationModuleException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Creates a role holding the checked permissions. The code follows the same
 * grammar as module slugs — lowercase, digits, underscores — and never
 * changes afterwards: it is what commands and migrations anchor on.
 */
final readonly class RoleCreateSubmitHandler implements RequestHandlerInterface
{
    private const string CODE_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/';

    public function __construct(
        private RequestGate $gate,
        private UserAdministration $users,
        private RoleFormSupport $form,
        private UrlGenerator $urls,
        private ResponseFactoryInterface $responses,
        private Triggers $triggers,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->gate->authorize(RoleListHandler::PERMISSION);

        $session = $request->getAttribute(SessionMiddleware::ATTRIBUTE);

        if (!$session instanceof Session) {
            throw AuthenticationModuleException::sessionMissing();
        }

        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];

        $code = trim(is_string($body['code'] ?? null) ? $body['code'] : '');
        $label = trim(is_string($body['label'] ?? null) ? $body['label'] : '');

        if (preg_match(self::CODE_PATTERN, $code) !== 1 || $label === '') {
            $session->set('error', 'authentication.role.invalid');

            return $this->redirectTo('authentication.role_create');
        }

        try {
            $id = $this->users->createRole($code, $label, $this->form->declaredOnly($body['permissions'] ?? null));
        } catch (UniqueConstraintViolationException) {
            $session->set('error', 'authentication.role.code_taken');

            return $this->redirectTo('authentication.role_create');
        }

        $this->triggers->fire('ROLE_CREATED', ['role_id' => $id, 'code' => $code]);
        $session->set('success', 'authentication.role.created');

        return $this->redirectTo('authentication.role', ['id' => $id]);
    }

    /**
     * @param array<string, int|string> $parameters
     */
    private function redirectTo(string $route, array $parameters = []): ResponseInterface
    {
        return $this->responses->createResponse(302)
            ->withHeader('Location', $this->urls->generate($route, $parameters));
    }
}
