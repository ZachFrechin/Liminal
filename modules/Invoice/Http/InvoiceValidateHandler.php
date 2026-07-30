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
use Liminal\Module\Invoice\Numbering\InvoiceValidation;
use Liminal\Module\Invoice\Repository\InvoiceRepository;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Validates a draft: at least one line, then the atomic freeze — number,
 * frozen totals, the issue date of today. INVOICE_VALIDATED fires strictly
 * post-commit, and never touches the EntityManager after the wrap: on a
 * failed validation the EM is closed, the flash rides the surviving
 * connection, and this handler has nothing more to ask of the ORM.
 */
final readonly class InvoiceValidateHandler implements RequestHandlerInterface
{
    public function __construct(
        private RequestGate $gate,
        private InvoiceRepository $invoices,
        private InvoiceValidation $validation,
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

        if ($this->invoices->linesOf($invoice) === []) {
            $session->set('error', 'invoice.form.needs_lines');

            return $this->redirectToDetail($id);
        }

        $number = $this->validation->validate($invoice);

        $this->triggers->fire('INVOICE_VALIDATED', [
            'invoice_id' => $id,
            'number' => $number,
            'total_incl' => $invoice->getTotalIncl(),
        ]);
        $session->set('success', 'invoice.form.validated');

        return $this->redirectToDetail($id);
    }

    private function redirectToDetail(int $id): ResponseInterface
    {
        return $this->responses->createResponse(302)
            ->withHeader('Location', $this->urls->generate('invoice.detail', ['id' => $id]));
    }
}
