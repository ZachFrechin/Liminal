<?php

declare(strict_types=1);

namespace Liminal\Module\Invoice\Http;

use Liminal\Http\Exception\HttpException;
use Liminal\Http\UrlGenerator;
use Liminal\Lib\Hook\Triggers;
use Liminal\Lib\Security\Authorization\RequestGate;
use Liminal\Lib\Security\Session\Session;
use Liminal\Lib\Security\Session\SessionMiddleware;
use Liminal\Module\Invoice\Exception\InvoiceModuleException;
use Liminal\Module\Invoice\InvoiceModule;
use Liminal\Module\Invoice\Repository\InvoiceRepository;
use Liminal\Module\Thirdparty\Repository\ThirdpartyRepository;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Edits a draft's header: the party and the dates. A validated invoice
 * refuses with a flash — the entity's own guard is defence in depth behind
 * this handler, never the user's error message.
 */
final readonly class InvoiceUpdateHandler implements RequestHandlerInterface
{
    public function __construct(
        private RequestGate $gate,
        private InvoiceRepository $invoices,
        private ThirdpartyRepository $thirdparties,
        private UrlGenerator $urls,
        private ResponseFactoryInterface $responses,
        private Triggers $triggers,
    ) {}

    /**
     * @throws HttpException as notFound() when no such invoice exists here
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->gate->authorize(InvoiceModule::READ);
        $this->gate->authorize(InvoiceModule::MANAGE);

        $session = $request->getAttribute(SessionMiddleware::ATTRIBUTE);

        if (!$session instanceof Session) {
            throw InvoiceModuleException::sessionMissing();
        }

        $raw = $request->getAttribute('id');
        $id = is_numeric($raw) ? (int) $raw : 0;

        $invoice = $this->invoices->byId($id);

        if ($invoice === null) {
            throw HttpException::notFound($request->getUri()->getPath());
        }

        if (!$invoice->isDraft()) {
            $session->set('error', 'invoice.form.immutable');

            return $this->redirectToDetail($id);
        }

        $body = $request->getParsedBody();
        $form = InvoiceForm::fromBody(is_array($body) ? $body : []);

        if ($form->firstError() !== null || $form->issuedOn === null) {
            $session->set('error', $form->firstError() ?? 'invoice.form.issued_on_invalid');

            return $this->redirectToDetail($id);
        }

        if ($this->thirdparties->byId($form->thirdpartyId) === null) {
            $session->set('error', 'invoice.form.thirdparty_unknown');

            return $this->redirectToDetail($id);
        }

        $invoice->update($form->thirdpartyId, $form->issuedOn, $form->dueOn);
        $this->invoices->flush();

        $this->triggers->fire('INVOICE_UPDATED', ['invoice_id' => $id]);
        $session->set('success', 'invoice.form.updated');

        return $this->redirectToDetail($id);
    }

    private function redirectToDetail(int $id): ResponseInterface
    {
        return $this->responses->createResponse(302)
            ->withHeader('Location', $this->urls->generate('invoice.detail', ['id' => $id]));
    }
}
