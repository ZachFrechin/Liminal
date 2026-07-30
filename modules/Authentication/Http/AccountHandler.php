<?php

declare(strict_types=1);

namespace Liminal\Module\Authentication\Http;

use Liminal\Lib\Database\Scope\CompanyContext;
use Liminal\Lib\Rendering\HtmlRenderer;
use Liminal\Lib\Security\Authentication\CurrentUser;
use Liminal\Module\Authentication\Administration\UserAdministration;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Where a successful login lands: who you are, which companies you reach —
 * by name, with a switch button each — and the one you are working in.
 *
 * It is also the phase's only proof that the whole stack composes — layout,
 * greeting, menu, company scope and sign-out in one page — which is why a login
 * needed somewhere to go before anything else could be asserted.
 */
final readonly class AccountHandler implements RequestHandlerInterface
{
    public function __construct(
        private HtmlRenderer $html,
        private CurrentUser $currentUser,
        private CompanyContext $context,
        private UserAdministration $administration,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->currentUser->get();

        return $this->html->respond(
            '@authentication/account.html.twig',
            [
                // Non-null here: the route is protected, so the authentication
                // middleware already refused anyone anonymous.
                'user' => $user,
                'companies' => $this->accessibleCompanies($user?->accessibleCompanyIds() ?? []),
                'currentCompany' => $this->context->currentId(),
            ],
            200,
            // An authenticated page on a shared machine must not sit in the
            // back-button cache after sign-out.
            ['Cache-Control' => 'no-store'],
        );
    }

    /**
     * The names behind the ids the identity carries. A company deleted between
     * the grant read and this render simply drops off the list — content
     * degrades, and the switch handler revalidates anyway.
     *
     * @param list<int> $accessibleIds
     *
     * @return list<array{id: int, code: string, name: string}>
     */
    private function accessibleCompanies(array $accessibleIds): array
    {
        return array_values(array_filter(
            $this->administration->listCompanies(),
            static fn(array $company): bool => in_array($company['id'], $accessibleIds, true),
        ));
    }
}
