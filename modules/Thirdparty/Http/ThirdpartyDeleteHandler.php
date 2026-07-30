<?php

declare(strict_types=1);

namespace Liminal\Module\Thirdparty\Http;

use Liminal\Http\Exception\HttpException;
use Liminal\Http\UrlGenerator;
use Liminal\Lib\Hook\Triggers;
use Liminal\Lib\Security\Authorization\RequestGate;
use Liminal\Lib\Security\Session\Session;
use Liminal\Lib\Security\Session\SessionMiddleware;
use Liminal\Module\Thirdparty\Exception\ThirdpartyModuleException;
use Liminal\Module\Thirdparty\Repository\ThirdpartyRepository;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Deletes a thirdparty of the working company. Nothing references
 * thirdparties yet; the day documents do, this handler grows the guard that
 * refuses to orphan them — recorded gap, not an accident.
 */
final readonly class ThirdpartyDeleteHandler implements RequestHandlerInterface
{
    public function __construct(
        private RequestGate $gate,
        private ThirdpartyRepository $thirdparties,
        private UrlGenerator $urls,
        private ResponseFactoryInterface $responses,
        private Triggers $triggers,
    ) {}

    /**
     * @throws HttpException as notFound() when no such thirdparty exists here
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->gate->authorize(ThirdpartyListHandler::READ);
        $this->gate->authorize(ThirdpartyListHandler::MANAGE);

        $session = $request->getAttribute(SessionMiddleware::ATTRIBUTE);

        if (!$session instanceof Session) {
            throw ThirdpartyModuleException::sessionMissing();
        }

        $raw = $request->getAttribute('id');
        $id = is_numeric($raw) ? (int) $raw : 0;

        $thirdparty = $this->thirdparties->byId($id);

        if ($thirdparty === null) {
            throw HttpException::notFound($request->getUri()->getPath());
        }

        $code = $thirdparty->getCode();

        $this->thirdparties->remove($thirdparty);
        $this->thirdparties->flush();
        $this->triggers->fire('THIRDPARTY_DELETED', ['thirdparty_id' => $id, 'code' => $code]);
        $session->set('success', 'thirdparty.form.deleted');

        return $this->responses->createResponse(302)
            ->withHeader('Location', $this->urls->generate('thirdparty.list'));
    }
}
