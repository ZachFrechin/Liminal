<?php

declare(strict_types=1);

namespace Liminal\Module\Thirdparty\Http\Api;

use Liminal\Lib\Api\ApiResponder;
use Liminal\Lib\Security\Authorization\RequestGate;
use Liminal\Module\Thirdparty\Entity\Thirdparty;
use Liminal\Module\Thirdparty\Http\ThirdpartyListHandler;
use Liminal\Module\Thirdparty\Repository\ThirdpartyRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The thirdparties of the working company as JSON — the same repository, the
 * same permission and the same company narrowing as the HTML list, in the
 * {data, meta} envelope. Read-only: the API's writes are a recorded gap.
 */
final readonly class ThirdpartyApiListHandler implements RequestHandlerInterface
{
    public function __construct(
        private RequestGate $gate,
        private ThirdpartyRepository $thirdparties,
        private ApiResponder $responder,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->gate->authorize(ThirdpartyListHandler::READ);

        $params = $request->getQueryParams();
        $page = is_numeric($params['page'] ?? null) ? (int) $params['page'] : 1;
        $query = is_string($params['q'] ?? null) ? $params['q'] : null;

        return $this->responder->collection(
            $this->thirdparties->page($page, $query),
            static fn(Thirdparty $thirdparty): array => ThirdpartyPayload::from($thirdparty),
        );
    }
}
