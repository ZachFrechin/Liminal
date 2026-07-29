<?php

declare(strict_types=1);

namespace Liminal\Tests\Integration\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;
use Liminal\Lib\Database\Scope\CompanyScoped;
use Liminal\Lib\Database\Scope\CompanyScopedTrait;

#[ORM\Entity]
#[ORM\Table(name: 'test_widget')]
#[ORM\Index(name: 'idx_test_widget_scope', columns: ['company_id', 'id'])]
class Widget implements CompanyScoped
{
    use CompanyScopedTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 128)]
    private string $label;

    public function __construct(string $label)
    {
        $this->label = $label;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function relabel(string $label): void
    {
        $this->label = $label;
    }
}
