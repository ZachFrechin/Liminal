<?php

declare(strict_types=1);

namespace Liminal\Module\Thirdparty\Http;

use Liminal\Http\Exception\HttpException;
use Liminal\Lib\Rendering\HtmlRenderer;
use Liminal\Lib\Security\Authorization\Gate;
use Liminal\Lib\Security\Authorization\RequestGate;
use Liminal\Module\Thirdparty\Repository\ThirdpartyRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * One thirdparty of the working company. A URL pointing into another company
 * — even an accessible one — answers 404: byId() narrows to the current
 * company, and a cross-company link must not leak across the working context.
 */
final readonly class ThirdpartyDetailHandler implements RequestHandlerInterface
{
    public function __construct(
        private HtmlRenderer $html,
        private RequestGate $gate,
        private Gate $allows,
        private ThirdpartyRepository $thirdparties,
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

        return $this->html->respond(
            '@thirdparty/thirdparty.html.twig',
            [
                'thirdparty' => $thirdparty,
                'canManage' => $this->allows->allows(ThirdpartyListHandler::MANAGE),
            ],
            200,
            ['Cache-Control' => 'no-store'],
        );
    }
}
