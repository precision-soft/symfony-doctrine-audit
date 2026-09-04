<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Test\Utility\Entity;

use Doctrine\ORM\Mapping as ORM;
use PrecisionSoft\Doctrine\Audit\Attribute\Auditable;

/** The root is auditable on purpose: a joined child emits one dto for its own table and a second for the root, so both classes must be auditable for the audit row to be complete. */
#[Auditable(true)]
#[ORM\Entity]
#[ORM\Table(name: 'vehicle')]
#[ORM\InheritanceType('JOINED')]
#[ORM\DiscriminatorColumn(name: 'vehicle_kind', type: 'string', length: 16)]
#[ORM\DiscriminatorMap(['car' => CarVehicle::class, 'van' => VanVehicle::class])]
abstract class AbstractVehicle
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 64)]
    private string $name = '';

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }
}
