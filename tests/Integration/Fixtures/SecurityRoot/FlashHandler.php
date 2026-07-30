<?php

declare(strict_types=1);

namespace Liminal\Tests\Integration\Fixtures\SecurityRoot;

use Liminal\Lib\Security\Session\Session;
use Liminal\Lib\Security\Session\SessionMiddleware;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The set-flash-then-redirect half of the flash contract; the layout's flash
 * region on the next page is the other half.
 */
final readonly class FlashHandler implements RequestHandlerInterface
{
    public function __construct(private ResponseFactoryInterface $responses) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $session = $request->getAttribute(SessionMiddleware::ATTRIBUTE);

        if ($session instanceof Session) {
            $session->set('success', 'Saved.');
        }

        return $this->responses->createResponse(302)->withHeader('Location', '/form');
    }
}
