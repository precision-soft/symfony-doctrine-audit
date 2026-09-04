<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Example\Entity;

use Doctrine\ORM\Mapping as ORM;
use PrecisionSoft\Doctrine\Audit\Attribute\Auditable;

/**
 * Joined inheritance: an offer writes one audit row for its own table and a second for this root, so both classes
 * have to be auditable for the trail of a discount to be complete.
 */
#[Auditable(true)]
#[ORM\Entity]
#[ORM\Table(name: 'offer')]
#[ORM\InheritanceType('JOINED')]
#[ORM\DiscriminatorColumn(name: 'offer_kind', type: 'string', length: 16)]
#[ORM\DiscriminatorMap(['discount' => DiscountOffer::class, 'bundle' => BundleOffer::class])]
abstract class AbstractOffer
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    protected ?int $id = null;

    #[ORM\Column(type: 'string', length: 64)]
    protected string $label = '';

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = $label;

        return $this;
    }
}
