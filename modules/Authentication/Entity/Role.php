<?php

declare(strict_types=1);

namespace Liminal\Module\Authentication\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A role: a named bundle of permission codes, global by design.
 *
 * RBAC-per-company comes entirely from the grant pivot (user × company × role),
 * so "the accountant role" stays one object whose permissions are edited once
 * rather than once per company.
 */
#[ORM\Entity]
#[ORM\Table(name: 'core_role')]
class Role
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 64, unique: true)]
    private string $code;

    #[ORM\Column(type: Types::STRING, length: 191)]
    private string $label;

    public function __construct(string $code, string $label)
    {
        $this->code = $code;
        $this->label = $label;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
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
