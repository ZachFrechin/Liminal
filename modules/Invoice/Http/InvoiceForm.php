<?php

declare(strict_types=1);

namespace Liminal\Module\Invoice\Http;

use DateTimeImmutable;

/**
 * The invoice header form, parsed and validated — errors are catalogue keys,
 * flashed by the handlers. Dates arrive as Y-m-d from the date inputs; the
 * due date is optional and must not precede the issue date.
 */
final readonly class InvoiceForm
{
    /**
     * @param list<string> $errors
     */
    private function __construct(
        public int $thirdpartyId,
        public ?DateTimeImmutable $issuedOn,
        public ?DateTimeImmutable $dueOn,
        public array $errors,
    ) {}

    /**
     * @param array<array-key, mixed> $body
     */
    public static function fromBody(array $body): self
    {
        $errors = [];

        $rawThirdparty = $body['thirdparty_id'] ?? null;
        $thirdpartyId = is_numeric($rawThirdparty) ? (int) $rawThirdparty : 0;

        if ($thirdpartyId < 1) {
            $errors[] = 'invoice.form.thirdparty_required';
        }

        $issuedOn = self::date($body['issued_on'] ?? null);

        if ($issuedOn === null) {
            $errors[] = 'invoice.form.issued_on_invalid';
        }

        $rawDue = $body['due_on'] ?? null;
        $dueOn = null;

        if (is_string($rawDue) && trim($rawDue) !== '') {
            $dueOn = self::date($rawDue);

            if ($dueOn === null) {
                $errors[] = 'invoice.form.due_on_invalid';
            } elseif ($issuedOn !== null && $dueOn < $issuedOn) {
                $errors[] = 'invoice.form.due_before_issue';
            }
        }

        return new self($thirdpartyId, $issuedOn, $dueOn, $errors);
    }

    public function firstError(): ?string
    {
        return $this->errors[0] ?? null;
    }

    private static function date(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value)) {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));

        return $parsed === false ? null : $parsed;
    }
}
