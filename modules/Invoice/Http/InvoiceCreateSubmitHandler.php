<?php

declare(strict_types=1);

namespace Liminal\Module\Invoice\Http;

use Liminal\Http\UrlGenerator;
use Liminal\Lib\Hook\Triggers;
use Liminal\Lib\Security\Authorization\RequestGate;
use Liminal\Lib\Security\Session\Session;
use Liminal\Lib\Security\Session\SessionMiddleware;
use Liminal\Module\Invoice\Entity\Invoice;
use Liminal\Module\Invoice\Exception\InvoiceModuleException;
use Liminal\Module\Invoice\InvoiceModule;
use Liminal\Module\Invoice\Repository\InvoiceRepository;
use Liminal\Module\Thirdparty\Repository\ThirdpartyRepository;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Creates a draft in the working company. The party check is byId() on the
 * thirdparty repository: existence and company scope in one read — a party
 * from another company simply does not exist here, and the schema's foreign
 * key backs the answer.
 */
final readonly class InvoiceCreateSubmitHandler implements RequestHandlerInterface
{
    public function __construct(
        private RequestGate $gate,
        private InvoiceRepository $invoices,
        private ThirdpartyRepository $thirdparties,
        private UrlGenerator $urls,
        private ResponseFactoryInterface $responses,
        private Triggers $triggers,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->gate->authorize(InvoiceModule::READ);
        $this->gate->authorize(InvoiceModule::MANAGE);

        $session = $request->getAttribute(SessionMiddleware::ATTRIBUTE);

        if (!$session instanceof Session) {
            throw InvoiceModuleException::sessionMissing();
        }

        $body = $request->getParsedBody();
        $form = InvoiceForm::fromBody(is_array($body) ? $body : []);

        if ($form->firstError() !== null || $form->issuedOn === null) {
            $session->set('error', $form->firstError() ?? 'invoice.form.issued_on_invalid');

            return $this->redirectTo('invoice.create');
        }

        if ($this->thirdparties->byId($form->thirdpartyId) === null) {
            $session->set('error', 'invoice.form.thirdparty_unknown');

            return $this->redirectTo('invoice.create');
        }

        $invoice = new Invoice($form->thirdpartyId, $form->issuedOn, $form->dueOn);

        $this->invoices->add($invoice);
        $this->invoices->flush();

        // Post-commit: getId() is only non-null past the flush.
        $this->triggers->fire('INVOICE_CREATED', [
            'invoice_id' => (int) $invoice->getId(),
            'thirdparty_id' => $form->thirdpartyId,
        ]);
        $session->set('success', 'invoice.form.created');

        return $this->redirectTo('invoice.detail', ['id' => (int) $invoice->getId()]);
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
