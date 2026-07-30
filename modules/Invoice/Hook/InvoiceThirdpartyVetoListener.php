<?php

declare(strict_types=1);

namespace Liminal\Module\Invoice\Hook;

use Liminal\Lib\Hook\Contract\HookListener;
use Liminal\Lib\Hook\HookContext;
use Liminal\Module\Invoice\Repository\InvoiceRepository;

/**
 * Answers thirdparty.deletion.veto: a party the working company's documents
 * still name must not disappear. This is the whole point of the hook — the
 * thirdparty module never learns this module exists; the edge points from
 * here to there, and the value that travels is a list of refusal reasons.
 *
 * Resolved lazily at dispatch time, so the EntityManager inside the
 * repository is only touched when a delete actually runs.
 */
final readonly class InvoiceThirdpartyVetoListener implements HookListener
{
    public function __construct(private InvoiceRepository $invoices) {}

    public function transform(mixed $value, HookContext $context): mixed
    {
        $reasons = is_array($value) ? $value : [];
        $thirdpartyId = $context->parameters['thirdparty_id'] ?? null;

        if (is_int($thirdpartyId) && $this->invoices->countForThirdparty($thirdpartyId) > 0) {
            $reasons[] = 'invoice.veto.referenced';
        }

        return $reasons;
    }
}
