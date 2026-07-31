<?php

declare(strict_types=1);

namespace Liminal\Module\Order\Hook;

use Liminal\Lib\Hook\Contract\HookListener;
use Liminal\Lib\Hook\HookContext;
use Liminal\Module\Order\Repository\OrderRepository;

/**
 * Answers thirdparty.deletion.veto: a party the working company's orders
 * still name must not disappear. Second responder on this hook after the
 * invoice module's — multiple listeners appending to the same reason list
 * is the hook working as designed, and neither module knows the other
 * answers.
 *
 * Resolved lazily at dispatch time, so the EntityManager inside the
 * repository is only touched when a delete actually runs.
 */
final readonly class OrderThirdpartyVetoListener implements HookListener
{
    public function __construct(private OrderRepository $orders) {}

    public function transform(mixed $value, HookContext $context): mixed
    {
        $reasons = is_array($value) ? $value : [];
        $thirdpartyId = $context->parameters['thirdparty_id'] ?? null;

        if (is_int($thirdpartyId) && $this->orders->countForThirdparty($thirdpartyId) > 0) {
            $reasons[] = 'order.veto.referenced';
        }

        return $reasons;
    }
}
