<?php

declare(strict_types=1);

namespace Liminal\Tests\Integration\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;
use Liminal\Lib\Database\Scope\EntityScoped;
use Liminal\Lib\Database\Scope\EntityScopedTrait;

#[ORM\Entity]
#[ORM\Table(name: 'test_widget')]
#[ORM\Index(name: 'idx_test_widget_scope', columns: ['entity_id', 'id'])]
class Widget implements EntityScoped
{
    use EntityScopedTrait;

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
}
