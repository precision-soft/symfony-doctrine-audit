<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Test\Utility\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class CircleShape extends AbstractShape
{
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $radius = null;

    public function getRadius(): ?int
    {
        return $this->radius;
    }

    public function setRadius(?int $radius): static
    {
        $this->radius = $radius;

        return $this;
    }
}
