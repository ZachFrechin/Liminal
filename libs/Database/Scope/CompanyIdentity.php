<?php

declare(strict_types=1);

namespace Liminal\Lib\Database\Scope;

use Closure;
use Doctrine\DBAL\Connection;

/**
 * The issuer block of a legal document: everything core_company knows about
 * one company's identity. A dedicated reader beside CompanyDirectory rather
 * than a widening of it — the directory's docblock is a contract (one
 * bounded read, the switcher's vocabulary, on every page), and the seller
 * block is document content, not shell vocabulary. The table stays this
 * lib's own: no other lib ever writes SQL against core_company.
 *
 * DBAL on a deferred connection, the CompanyDirectory shape exactly.
 */
final readonly class CompanyIdentity
{
    /**
     * @param Closure(): Connection $connection
     */
    public function __construct(private Closure $connection) {}

    /**
     * @return ?array{name: string, code: string, address: ?string, zip: ?string,
     *                town: ?string, country_code: ?string, vat_number: ?string,
     *                registration: ?string, legal_mentions: ?string}
     */
    public function identityOf(int $id): ?array
    {
        $row = ($this->connection)()->fetchAssociative(
            'SELECT name, code, address, zip, town, country_code, vat_number, registration, legal_mentions
             FROM core_company WHERE id = ?',
            [$id],
        );

        if ($row === false) {
            return null;
        }

        return [
            'name' => is_string($row['name']) ? $row['name'] : '',
            'code' => is_string($row['code']) ? $row['code'] : '',
            'address' => is_string($row['address']) ? $row['address'] : null,
            'zip' => is_string($row['zip']) ? $row['zip'] : null,
            'town' => is_string($row['town']) ? $row['town'] : null,
            'country_code' => is_string($row['country_code']) ? $row['country_code'] : null,
            'vat_number' => is_string($row['vat_number']) ? $row['vat_number'] : null,
            'registration' => is_string($row['registration']) ? $row['registration'] : null,
            'legal_mentions' => is_string($row['legal_mentions']) ? $row['legal_mentions'] : null,
        ];
    }
}
