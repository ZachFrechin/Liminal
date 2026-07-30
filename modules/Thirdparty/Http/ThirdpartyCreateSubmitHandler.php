<?php

declare(strict_types=1);

namespace Liminal\Module\Thirdparty\Http;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Liminal\Http\UrlGenerator;
use Liminal\Lib\Security\Authorization\RequestGate;
use Liminal\Lib\Security\Session\Session;
use Liminal\Lib\Security\Session\SessionMiddleware;
use Liminal\Module\Thirdparty\Entity\Thirdparty;
use Liminal\Module\Thirdparty\Exception\ThirdpartyModuleException;
use Liminal\Module\Thirdparty\Repository\ThirdpartyRepository;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Creates a thirdparty in the working company — prePersist stamps it, the
 * composite unique keeps its code singular here and free elsewhere.
 *
 * codeTaken() is the polite pre-check; the constraint is the judge, and the
 * race between the two is caught at flush (which closes the EntityManager —
 * harmless here: the flash rides the same still-open connection, and the
 * response is a redirect).
 */
final readonly class ThirdpartyCreateSubmitHandler implements RequestHandlerInterface
{
    public function __construct(
        private RequestGate $gate,
        private ThirdpartyRepository $thirdparties,
        private UrlGenerator $urls,
        private ResponseFactoryInterface $responses,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->gate->authorize(ThirdpartyListHandler::READ);
        $this->gate->authorize(ThirdpartyListHandler::MANAGE);

        $session = $request->getAttribute(SessionMiddleware::ATTRIBUTE);

        if (!$session instanceof Session) {
            throw ThirdpartyModuleException::sessionMissing();
        }

        $body = $request->getParsedBody();
        $form = ThirdpartyForm::fromBody(is_array($body) ? $body : []);

        if ($form->firstError() !== null) {
            $session->set('error', $form->firstError());

            return $this->redirectTo('thirdparty.create');
        }

        if ($this->thirdparties->codeTaken($form->code)) {
            $session->set('error', 'thirdparty.form.code_taken');

            return $this->redirectTo('thirdparty.create');
        }

        $thirdparty = new Thirdparty($form->code, $form->name);
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

        try {
            $this->thirdparties->add($thirdparty);
            $this->thirdparties->flush();
        } catch (UniqueConstraintViolationException) {
            // The race the pre-check cannot close.
            $session->set('error', 'thirdparty.form.code_taken');

            return $this->redirectTo('thirdparty.create');
        }

        $session->set('success', 'thirdparty.form.created');

        return $this->redirectTo('thirdparty.detail', ['id' => (int) $thirdparty->getId()]);
    }

    /**
     * @param array<string, int|string> $parameters
     */
    private function redirectTo(string $route, array $parameters = []): ResponseInterface
    {
        return $this->responses->createResponse(302)
            ->withHeader('Location', $this->urls->generate($route, $parameters));
    }
}
