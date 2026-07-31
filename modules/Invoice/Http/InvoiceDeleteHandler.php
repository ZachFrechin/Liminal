<?php

declare(strict_types=1);

namespace Liminal\Module\Invoice\Http;

use Liminal\Http\Exception\HttpException;
use Liminal\Http\UrlGenerator;
use Liminal\Lib\Hook\Hooks;
use Liminal\Lib\Hook\Triggers;
use Liminal\Lib\Security\Authorization\RequestGate;
use Liminal\Lib\Security\Session\Session;
use Liminal\Lib\Security\Session\SessionMiddleware;
use Liminal\Module\Invoice\Exception\InvoiceModuleException;
use Liminal\Module\Invoice\InvoiceModule;
use Liminal\Module\Invoice\Repository\InvoiceRepository;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Deletes a DRAFT — its lines ride the schema's cascade. A validated
 * invoice never disappears: the refusal is a flash, and there is no force
 * flag anywhere for a reason.
 *
 * Even a draft can be spoken for: invoice.deletion.veto dispatches AFTER
 * the immutability guard (a validated invoice must hear "immutable", not a
 * veto reason) and before remove — a converted order's draft invoice is
 * exactly the case.
 */
final readonly class InvoiceDeleteHandler implements RequestHandlerInterface
{
    public function __construct(
        private RequestGate $gate,
        private InvoiceRepository $invoices,
        private Hooks $hooks,
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

            return $this->responses->createResponse(302)
                ->withHeader('Location', $this->urls->generate('invoice.detail', ['id' => $id]));
        }

        $reasons = $this->hooks->filter('invoice.deletion.veto', [], ['invoice_id' => $id]);

        if (!is_array($reasons) || !array_is_list($reasons) || $reasons !== array_filter($reasons, is_string(...))) {
            throw InvoiceModuleException::malformedVeto(get_debug_type($reasons));
        }

        if ($reasons !== []) {
            $session->set('error', $reasons[0]);

            return $this->responses->createResponse(302)
                ->withHeader('Location', $this->urls->generate('invoice.detail', ['id' => $id]));
        }

        $this->invoices->remove($invoice);
        $this->invoices->flush();

        $this->triggers->fire('INVOICE_DELETED', ['invoice_id' => $id]);
        $session->set('success', 'invoice.form.deleted');

        return $this->responses->createResponse(302)
            ->withHeader('Location', $this->urls->generate('invoice.list'));
    }
}
