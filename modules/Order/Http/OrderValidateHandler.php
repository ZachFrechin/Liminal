<?php

declare(strict_types=1);

namespace Liminal\Module\Order\Http;

use Liminal\Http\Exception\HttpException;
use Liminal\Http\UrlGenerator;
use Liminal\Lib\Hook\Triggers;
use Liminal\Lib\Security\Authorization\RequestGate;
use Liminal\Lib\Security\Session\Session;
use Liminal\Lib\Security\Session\SessionMiddleware;
use Liminal\Module\Order\Exception\OrderModuleException;
use Liminal\Module\Order\Numbering\OrderValidation;
use Liminal\Module\Order\OrderModule;
use Liminal\Module\Order\Repository\OrderRepository;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Validates a draft: at least one line, then the atomic freeze — number,
 * frozen totals, the issue date of today. ORDER_VALIDATED fires strictly
 * post-commit, and never touches the EntityManager after the wrap: on a
 * failed validation the EM is closed, the flash rides the surviving
 * connection, and this handler has nothing more to ask of the ORM.
 */
final readonly class OrderValidateHandler implements RequestHandlerInterface
{
    public function __construct(
        private RequestGate $gate,
        private OrderRepository $orders,
        private OrderValidation $validation,
        private UrlGenerator $urls,
        private ResponseFactoryInterface $responses,
        private Triggers $triggers,
    ) {}

    /**
     * @throws HttpException as notFound() when no such order exists here
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->gate->authorize(OrderModule::READ);
        $this->gate->authorize(OrderModule::MANAGE);

        $session = $request->getAttribute(SessionMiddleware::ATTRIBUTE);

        if (!$session instanceof Session) {
            throw OrderModuleException::sessionMissing();
        }

        $raw = $request->getAttribute('id');
        $id = is_numeric($raw) ? (int) $raw : 0;

        $order = $this->orders->byId($id);

        if ($order === null) {
            throw HttpException::notFound($request->getUri()->getPath());
        }

        if (!$order->isDraft()) {
            $session->set('error', 'order.form.immutable');

            return $this->redirectToDetail($id);
        }

        if ($this->orders->linesOf($order) === []) {
            $session->set('error', 'order.form.needs_lines');

            return $this->redirectToDetail($id);
        }

        $number = $this->validation->validate($order);

        $this->triggers->fire('ORDER_VALIDATED', [
            'order_id' => $id,
            'number' => $number,
            'total_incl' => $order->getTotalIncl(),
        ]);
        $session->set('success', 'order.form.validated');

        return $this->redirectToDetail($id);
    }

    private function redirectToDetail(int $id): ResponseInterface
    {
        return $this->responses->createResponse(302)
            ->withHeader('Location', $this->urls->generate('order.detail', ['id' => $id]));
    }
}
