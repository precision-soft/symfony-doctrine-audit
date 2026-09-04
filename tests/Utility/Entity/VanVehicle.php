<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Test\Utility\Entity;

use Doctrine\ORM\Mapping as ORM;
use PrecisionSoft\Doctrine\Audit\Attribute\Auditable;

/** The joined counterpart of `SquareShape`: a child that opts out of an auditable root, which emits a root dto its own class no longer accepts. */
#[Auditable(false)]
#[ORM\Entity]
#[ORM\Table(name: 'van_vehicle')]
class VanVehicle extends AbstractVehicle
{
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $payload = null;

    public function getPayload(): ?int
    {
        return $this->payload;
    }

    public function setPayload(?int $payload): static
    {
        $this->payload = $payload;

        return $this;
    }
}
