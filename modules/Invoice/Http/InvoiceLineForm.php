<?php

declare(strict_types=1);

namespace Liminal\Module\Invoice\Http;

/**
 * One invoice line, parsed and validated. The caps are load-bearing, not
 * cosmetic: quantity ≤ 999 999,99 and unit price ≤ 99 999 999,99 keep the
 * cents product inside int64 with a ninefold margin — one shared wide regex
 * would let the multiplication overflow into float-fabricated cents. The
 * rate cap keeps DECIMAL(5,2) reachable only through a flash, never through
 * an SQL range error. The French comma normalises to a point.
 */
final readonly class InvoiceLineForm
{
    private const string QUANTITY = '/^\d{1,6}([.,]\d{1,2})?$/';

    private const string UNIT_PRICE = '/^\d{1,8}([.,]\d{1,2})?$/';

    private const string VAT_RATE = '/^\d{1,3}([.,]\d{1,2})?$/';

    /**
     * @param list<string> $errors
     */
    private function __construct(
        public string $label,
        public string $quantity,
        public string $unitPrice,
        public string $vatRate,
        public array $errors,
    ) {}

    /**
     * @param array<array-key, mixed> $body
     */
    public static function fromBody(array $body): self
    {
        $errors = [];

        $label = is_string($body['label'] ?? null) ? trim($body['label']) : '';

        if ($label === '' || mb_strlen($label) > 255) {
            $errors[] = 'invoice.form.label_required';
        }

        $quantity = self::amount($body['quantity'] ?? null, self::QUANTITY, 'invoice.form.quantity_invalid', $errors);
        $unitPrice = self::amount($body['unit_price'] ?? null, self::UNIT_PRICE, 'invoice.form.unit_price_invalid', $errors);
        $vatRate = self::amount($body['vat_rate'] ?? null, self::VAT_RATE, 'invoice.form.vat_rate_invalid', $errors);

        if ($vatRate !== '' && (float) $vatRate > 100.0) {
            // Comparison only — the stored value stays the validated string.
            $errors[] = 'invoice.form.vat_rate_invalid';
        }

        return new self($label, $quantity, $unitPrice, $vatRate, $errors);
    }

    public function firstError(): ?string
    {
        return $this->errors[0] ?? null;
    }

    /**
     * @param list<string> $errors
     */
    private static function amount(mixed $value, string $pattern, string $error, array &$errors): string
    {
        $value = is_string($value) ? trim($value) : '';

        if (preg_match($pattern, $value) !== 1) {
            $errors[] = $error;

            return '';
        }

        return str_replace(',', '.', $value);
    }
}
