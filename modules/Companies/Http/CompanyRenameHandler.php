<?php

declare(strict_types=1);

namespace Liminal\Module\Companies\Http;

use Liminal\Http\Exception\HttpException;
use Liminal\Http\UrlGenerator;
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
 * Renames a company. The code has no input anywhere: it never moves.
 */
final readonly class CompanyRenameHandler implements RequestHandlerInterface
{
    public function __construct(
        private RequestGate $gate,
        private CompanyAdministration $companies,
        private UrlGenerator $urls,
        private ResponseFactoryInterface $responses,
    ) {}

    /**
     * @throws HttpException as notFound() when no such company exists
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->gate->authorize(CompanyListHandler::PERMISSION);

        $session = $request->getAttribute(SessionMiddleware::ATTRIBUTE);

        if (!$session instanceof Session) {
            throw CompaniesModuleException::sessionMissing();
        }

        $raw = $request->getAttribute('id');
        $id = is_numeric($raw) ? (int) $raw : 0;

        if ($this->companies->companyById($id) === null) {
            throw HttpException::notFound($request->getUri()->getPath());
        }

        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];

        $name = trim(is_string($body['name'] ?? null) ? $body['name'] : '');

        if ($name === '') {
            $session->set('error', 'companies.company.name_required');
        } else {
            $this->companies->rename($id, $name);
            $session->set('success', 'companies.company.renamed');
        }

        return $this->responses->createResponse(302)
            ->withHeader('Location', $this->urls->generate('companies.company', ['id' => $id]));
    }
}
