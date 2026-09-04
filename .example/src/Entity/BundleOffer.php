<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Example\Entity;

use Doctrine\ORM\Mapping as ORM;
use PrecisionSoft\Doctrine\Audit\Attribute\Auditable;

/** Opts out of the audited root, so the trail keeps nothing about it and the audit schema gives it no table. */
#[Auditable(false)]
#[ORM\Entity]
#[ORM\Table(name: 'bundle_offer')]
class BundleOffer extends AbstractOffer
{
    #[ORM\Column(name: 'item_count', type: 'integer')]
    protected int $itemCount = 0;

    public function getItemCount(): int
    {
        return $this->itemCount;
    }

    public function setItemCount(int $itemCount): static
    {
        $this->itemCount = $itemCount;

        return $this;
    }
}
