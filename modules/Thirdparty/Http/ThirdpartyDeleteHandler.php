<?php

declare(strict_types=1);

namespace Liminal\Module\Thirdparty\Http;

use Liminal\Http\Exception\HttpException;
use Liminal\Http\UrlGenerator;
use Liminal\Lib\Hook\Hooks;
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
 * Deletes a thirdparty of the working company — unless a document holds it.
 *
 * The guard this handler always promised is the thirdparty.deletion.veto
 * hook: whoever keeps documents naming the party (the invoice module today,
 * anyone tomorrow) appends a refusal reason, and this module never learns
 * who answered. The value that travels is a list of catalogue keys; being
 * the declarer, this dispatch site validates that shape and flashes the
 * first reason. The schema's RESTRICT foreign key stands behind it for any
 * writer that bypasses this handler.
 */
final readonly class ThirdpartyDeleteHandler implements RequestHandlerInterface
{
    public function __construct(
        private RequestGate $gate,
        private ThirdpartyRepository $thirdparties,
        private UrlGenerator $urls,
        private ResponseFactoryInterface $responses,
        private Triggers $triggers,
        private Hooks $hooks,
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

        $reasons = $this->hooks->filter('thirdparty.deletion.veto', [], ['thirdparty_id' => $id]);

        if (!is_array($reasons) || !array_is_list($reasons) || $reasons !== array_filter($reasons, is_string(...))) {
            throw ThirdpartyModuleException::malformedVeto(get_debug_type($reasons));
        }

        if ($reasons !== []) {
            $session->set('error', $reasons[0]);

            return $this->responses->createResponse(302)
                ->withHeader('Location', $this->urls->generate('thirdparty.detail', ['id' => $id]));
        }

        $this->thirdparties->remove($thirdparty);
        $this->thirdparties->flush();
        $this->triggers->fire('THIRDPARTY_DELETED', ['thirdparty_id' => $id, 'code' => $code]);
        $session->set('success', 'thirdparty.form.deleted');

        return $this->responses->createResponse(302)
            ->withHeader('Location', $this->urls->generate('thirdparty.list'));
    }
}
