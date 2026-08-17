<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Test\Utility\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Carries no `#[Auditable]` on purpose: it inherits the root's. */
#[ORM\Entity]
#[ORM\Table(name: 'car_vehicle')]
class CarVehicle extends AbstractVehicle
{
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $doors = null;

    public function getDoors(): ?int
    {
        return $this->doors;
    }

    public function setDoors(?int $doors): static
    {
        $this->doors = $doors;

        return $this;
    }
}
