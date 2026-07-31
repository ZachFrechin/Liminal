<?php

declare(strict_types=1);

namespace Liminal\Module\Order\Http;

use DateTimeImmutable;

/**
 * The order header form, parsed and validated — errors are catalogue keys,
 * flashed by the handlers. Dates arrive as Y-m-d from the date inputs; the
 * wanted date is optional and must not precede the issue date.
 */
final readonly class OrderForm
{
    /**
     * @param list<string> $errors
     */
    private function __construct(
        public int $thirdpartyId,
        public ?DateTimeImmutable $issuedOn,
        public ?DateTimeImmutable $wantedOn,
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
            $errors[] = 'order.form.thirdparty_required';
        }

        $issuedOn = self::date($body['issued_on'] ?? null);

        if ($issuedOn === null) {
            $errors[] = 'order.form.issued_on_invalid';
        }

        $rawWanted = $body['wanted_on'] ?? null;
        $wantedOn = null;

        if (is_string($rawWanted) && trim($rawWanted) !== '') {
            $wantedOn = self::date($rawWanted);

            if ($wantedOn === null) {
                $errors[] = 'order.form.wanted_on_invalid';
            } elseif ($issuedOn !== null && $wantedOn < $issuedOn) {
                $errors[] = 'order.form.wanted_before_issue';
            }
        }

        return new self($thirdpartyId, $issuedOn, $wantedOn, $errors);
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
