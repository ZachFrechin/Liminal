<?php

declare(strict_types=1);

namespace Liminal\Module\Companies\Http;

use Liminal\Http\Exception\HttpException;
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
 * Edits a company's identity: the issuer block every legal document prints.
 * All fields optional — an empty input becomes null, and the document
 * degrades content instead of printing empty labels. Lengths are clamped to
 * the columns so no SQL range error is reachable from a form.
 */
final readonly class CompanyIdentityHandler implements RequestHandlerInterface
{
    public function __construct(
        private RequestGate $gate,
        private CompanyAdministration $companies,
        private UrlGenerator $urls,
        private ResponseFactoryInterface $responses,
        private Triggers $triggers,
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

        $this->companies->updateIdentity(
            $id,
            $this->field($body, 'address', 255),
            $this->field($body, 'zip', 16),
            $this->field($body, 'town', 128),
            $this->countryCode($body),
            $this->field($body, 'vat_number', 32),
            $this->field($body, 'registration', 64),
            $this->field($body, 'legal_mentions', 4000),
        );

        $this->triggers->fire('COMPANY_UPDATED', ['company_id' => $id], companyId: $id);
        $session->set('success', 'companies.company.identity_saved');

        return $this->responses->createResponse(302)
            ->withHeader('Location', $this->urls->generate('companies.company', ['id' => $id]));
    }

    /**
     * @param array<array-key, mixed> $body
     */
    private function field(array $body, string $name, int $max): ?string
    {
        $value = is_string($body[$name] ?? null) ? trim($body[$name]) : '';

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    /**
     * @param array<array-key, mixed> $body
     */
    private function countryCode(array $body): ?string
    {
        $value = $this->field($body, 'country_code', 2);

        return $value === null ? null : strtoupper($value);
    }
}
