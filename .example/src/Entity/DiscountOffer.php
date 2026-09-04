<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Example\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Carries no `#[Auditable]`: it inherits the root's, which is what makes a joined child audited by default. */
#[ORM\Entity]
#[ORM\Table(name: 'discount_offer')]
class DiscountOffer extends AbstractOffer
{
    #[ORM\Column(type: 'integer')]
    protected int $percentage = 0;

    public function getPercentage(): int
    {
        return $this->percentage;
    }

    public function setPercentage(int $percentage): static
    {
        $this->percentage = $percentage;

        return $this;
    }
}
