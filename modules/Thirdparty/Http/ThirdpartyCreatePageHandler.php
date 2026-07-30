<?php

declare(strict_types=1);

namespace Liminal\Module\Thirdparty\Http;

use Liminal\Lib\Rendering\HtmlRenderer;
use Liminal\Lib\Security\Authorization\RequestGate;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The form that mints a thirdparty in the working company.
 */
final readonly class ThirdpartyCreatePageHandler implements RequestHandlerInterface
{
    public function __construct(
        private HtmlRenderer $html,
        private RequestGate $gate,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // Manage presumes read: both, always in this order.
        $this->gate->authorize(ThirdpartyListHandler::READ);
        $this->gate->authorize(ThirdpartyListHandler::MANAGE);

        return $this->html->respond(
            '@thirdparty/thirdparty_create.html.twig',
            [],
            200,
            ['Cache-Control' => 'no-store'],
        );
    }
}
