<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Test\Utility\Entity;

use Doctrine\ORM\Mapping as ORM;
use PrecisionSoft\Doctrine\Audit\Attribute\Auditable;

#[Auditable(false)]
#[ORM\Entity]
class SquareShape extends AbstractShape
{
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $side = null;

    public function getSide(): ?int
    {
        return $this->side;
    }

    public function setSide(?int $side): static
    {
        $this->side = $side;

        return $this;
    }
}
