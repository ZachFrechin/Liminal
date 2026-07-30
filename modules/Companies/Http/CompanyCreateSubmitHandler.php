<?php

declare(strict_types=1);

namespace Liminal\Module\Companies\Http;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Liminal\Http\UrlGenerator;
use Liminal\Lib\Hook\Triggers;
use Liminal\Lib\Security\Authorization\RequestGate;
use Liminal\Lib\Security\Session\Session;
use Liminal\Lib\Security\Session\SessionMiddleware;
use Liminal\Module\Companies\Administration\CompanyAdministration;
use Liminal\Module\Companies\Exception\CompaniesModuleException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Creates the company — row and module enablements in one transaction — and
 * lands on its detail page, where the per-module state (including anything
 * declared but not installed) is standing information rather than a one-shot
 * warning.
 */
final readonly class CompanyCreateSubmitHandler implements RequestHandlerInterface
{
    public function __construct(
        private RequestGate $gate,
        private CompanyAdministration $companies,
        private UrlGenerator $urls,
        private ResponseFactoryInterface $responses,
        private Triggers $triggers,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->gate->authorize(CompanyListHandler::PERMISSION);

        $session = $request->getAttribute(SessionMiddleware::ATTRIBUTE);

        if (!$session instanceof Session) {
            throw CompaniesModuleException::sessionMissing();
        }

        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];

        $code = strtoupper(trim(is_string($body['code'] ?? null) ? $body['code'] : ''));
        $name = trim(is_string($body['name'] ?? null) ? $body['name'] : '');

        if (preg_match(CompanyAdministration::CODE_PATTERN, $code) !== 1 || $name === '') {
            $session->set('error', 'companies.company.invalid');

            return $this->redirectTo('companies.create');
        }

        try {
            $creation = $this->companies->create($code, $name);
        } catch (UniqueConstraintViolationException) {
            $session->set('error', 'companies.company.code_taken');

            return $this->redirectTo('companies.create');
        }

        // The event belongs to the NEWBORN company, not the working one.
        $this->triggers->fire(
            'COMPANY_CREATED',
            ['company_id' => $creation->companyId, 'code' => $code, 'not_installed' => implode(',', $creation->notInstalled)],
            companyId: $creation->companyId,
        );

        $session->set(
            $creation->notInstalled === [] ? 'success' : 'error',
            $creation->notInstalled === []
                ? 'companies.company.created'
                : 'companies.company.created_with_missing',
        );

        return $this->redirectTo('companies.company', ['id' => $creation->companyId]);
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
