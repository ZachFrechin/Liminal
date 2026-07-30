<?php

declare(strict_types=1);

namespace Liminal\Module\Thirdparty\Http;

/**
 * The submitted thirdparty form, parsed once and validated once — create and
 * update read the exact same fields, so they share the exact same reading.
 *
 * Codes are free text (a business reference has no imposed grammar — unlike
 * company and role codes, which commands anchor on), just bounded and
 * non-empty. Empty optional fields become null: the database has one way to
 * say "absent".
 */
final readonly class ThirdpartyForm
{
    private function __construct(
        public string $code,
        public string $name,
        public ?string $alias,
        public bool $customer,
        public bool $supplier,
        public ?string $email,
        public ?string $phone,
        public ?string $address,
        public ?string $zip,
        public ?string $town,
        public ?string $countryCode,
        public ?string $vatNumber,
        public ?string $notes,
        public bool $active,
        /** @var list<string> */
        public array $errors,
    ) {}

    /**
     * @param array<array-key, mixed> $body
     */
    public static function fromBody(array $body): self
    {
        $string = static function (string $key) use ($body): string {
            $value = $body[$key] ?? null;

            return is_string($value) ? trim($value) : '';
        };
        $optional = static fn(string $key): ?string => $string($key) === '' ? null : $string($key);

        $code = $string('code');
        $name = $string('name');
        $email = $optional('email');
        $countryCode = $optional('country_code');
        $countryCode = $countryCode === null ? null : strtoupper($countryCode);

        $errors = [];

        if ($code === '' || mb_strlen($code) > 32) {
            $errors[] = 'thirdparty.form.code_invalid';
        }

        if ($name === '' || mb_strlen($name) > 191) {
            $errors[] = 'thirdparty.form.name_required';
        }

        if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = 'thirdparty.form.email_invalid';
        }

        if ($countryCode !== null && preg_match('/^[A-Z]{2}$/', $countryCode) !== 1) {
            $errors[] = 'thirdparty.form.country_invalid';
        }

        return new self(
            $code,
            $name,
            $optional('alias'),
            ($body['customer'] ?? null) === '1',
            ($body['supplier'] ?? null) === '1',
            $email,
            $optional('phone'),
            $optional('address'),
            $optional('zip'),
            $optional('town'),
            $countryCode,
            $optional('vat_number'),
            $optional('notes'),
            ($body['active'] ?? '1') === '1',
            $errors,
        );
    }

    public function firstError(): ?string
    {
        return $this->errors[0] ?? null;
    }
}
