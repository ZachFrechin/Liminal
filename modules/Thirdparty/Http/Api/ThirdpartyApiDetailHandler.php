<?php

declare(strict_types=1);

namespace Liminal\Module\Thirdparty\Http\Api;

use Liminal\Http\Exception\HttpException;
use Liminal\Lib\Api\ApiResponder;
use Liminal\Lib\Security\Authorization\RequestGate;
use Liminal\Module\Thirdparty\Http\ThirdpartyListHandler;
use Liminal\Module\Thirdparty\Repository\ThirdpartyRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * One thirdparty of the working company as JSON. byId() narrows to the
 * current company, so a foreign id answers the same 404 as a nonexistent
 * one — the API leaks exactly as much as the screens: nothing.
 */
final readonly class ThirdpartyApiDetailHandler implements RequestHandlerInterface
{
    public function __construct(
        private RequestGate $gate,
        private ThirdpartyRepository $thirdparties,
        private ApiResponder $responder,
    ) {}

    /**
     * @throws HttpException as notFound() when no such thirdparty exists here
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->gate->authorize(ThirdpartyListHandler::READ);

        $raw = $request->getAttribute('id');
        $id = is_numeric($raw) ? (int) $raw : 0;

        $thirdparty = $this->thirdparties->byId($id);

        if ($thirdparty === null) {
            throw HttpException::notFound($request->getUri()->getPath());
        }

        return $this->responder->item(ThirdpartyPayload::from($thirdparty));
    }
}
