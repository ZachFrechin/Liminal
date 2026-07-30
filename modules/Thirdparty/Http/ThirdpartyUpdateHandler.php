<?php

declare(strict_types=1);

namespace Liminal\Module\Thirdparty\Http;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
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
 * Edits a thirdparty of the working company — everything except the company,
 * which is write-once by the flush gate whatever this handler does. A code
 * change re-enters the per-company uniqueness race, checked and caught the
 * same way creation is.
 */
final readonly class ThirdpartyUpdateHandler implements RequestHandlerInterface
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

        $body = $request->getParsedBody();
        $form = ThirdpartyForm::fromBody(is_array($body) ? $body : []);

        if ($form->firstError() !== null) {
            $session->set('error', $form->firstError());

            return $this->backToDetail($id);
        }

        if ($form->code !== $thirdparty->getCode() && $this->thirdparties->codeTaken($form->code, $id)) {
            $session->set('error', 'thirdparty.form.code_taken');

            return $this->backToDetail($id);
        }

        $thirdparty->changeCode($form->code);
        $thirdparty->update(
            $form->name,
            $form->alias,
            $form->customer,
            $form->supplier,
            $form->email,
            $form->phone,
            $form->address,
            $form->zip,
            $form->town,
            $form->countryCode,
            $form->vatNumber,
            $form->notes,
        );

        if ($form->active) {
            $thirdparty->activate();
        } else {
            $thirdparty->deactivate();
        }

        try {
            $this->thirdparties->flush();
        } catch (UniqueConstraintViolationException) {
            $session->set('error', 'thirdparty.form.code_taken');

            return $this->backToDetail($id);
        }

        $this->triggers->fire('THIRDPARTY_UPDATED', ['thirdparty_id' => $id, 'code' => $form->code]);
        $session->set('success', 'thirdparty.form.updated');

        return $this->backToDetail($id);
    }

    private function backToDetail(int $id): ResponseInterface
    {
        return $this->responses->createResponse(302)
            ->withHeader('Location', $this->urls->generate('thirdparty.detail', ['id' => $id]));
    }
}
