<?php

declare(strict_types=1);

namespace Liminal\Tests\Integration\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;
use Liminal\Lib\Database\Scope\CompanyScoped;
use Liminal\Lib\Database\Scope\CompanyScopedTrait;

/**
 * Second scoped entity: proves the filter applies per class rather than to one
 * hard-coded table.
 */
#[ORM\Entity]
#[ORM\Table(name: 'test_gadget')]
#[ORM\Index(name: 'idx_test_gadget_scope', columns: ['company_id', 'id'])]
class Gadget implements CompanyScoped
{
    use CompanyScopedTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 128)]
    private string $reference;

    public function __construct(string $reference)
    {
        $this->reference = $reference;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getReference(): string
    {
        return $this->reference;
    }
}
