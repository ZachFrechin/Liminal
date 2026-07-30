<?php

declare(strict_types=1);

namespace Liminal\Module\Invoice\Http;

use Liminal\Lib\Rendering\HtmlRenderer;
use Liminal\Lib\Security\Authorization\RequestGate;
use Liminal\Module\Invoice\InvoiceModule;
use Liminal\Module\Thirdparty\Repository\ThirdpartyRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The empty draft form. The party select consumes the thirdparty module's
 * repository directly — the sanctioned one-direction dependency: an invoice
 * cannot exist without the party it bills.
 */
final readonly class InvoiceCreatePageHandler implements RequestHandlerInterface
{
    public function __construct(
        private HtmlRenderer $html,
        private RequestGate $gate,
        private ThirdpartyRepository $thirdparties,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->gate->authorize(InvoiceModule::READ);
        $this->gate->authorize(InvoiceModule::MANAGE);

        return $this->html->respond(
            '@invoice/invoice_create.html.twig',
            [
                'thirdparties' => $this->thirdparties->activeForSelect(),
                'today' => date('Y-m-d'),
            ],
            200,
            ['Cache-Control' => 'no-store'],
        );
    }
}
