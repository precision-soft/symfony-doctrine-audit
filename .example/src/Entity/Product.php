<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Example\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use PrecisionSoft\Doctrine\Audit\Attribute\Auditable;
use PrecisionSoft\Doctrine\Audit\Attribute\Ignore;

/**
 * The audited heart of the catalogue: a price the trail must explain, an owning collection of channels, and two
 * columns that are deliberately kept out of the trail - one per mechanism.
 */
#[Auditable(true)]
#[ORM\Entity]
#[ORM\Table(name: 'product')]
class Product
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    protected ?int $id = null;

    #[ORM\Column(type: 'string', length: 64)]
    protected string $name = '';

    #[ORM\Column(name: 'price_in_cents', type: 'integer')]
    protected int $priceInCents = 0;

    #[ORM\Column(type: 'string', length: 3)]
    protected string $currency = 'EUR';

    /** Excluded from the trail by the attribute: the supplier's terms are not ours to keep. */
    #[Ignore]
    #[ORM\Column(name: 'supplier_terms', type: 'string', length: 64)]
    protected string $supplierTerms = '';

    /** Excluded through the auditor's global `ignored_fields`, so the same column is quiet for every audited entity. */
    #[ORM\Column(type: 'string', length: 32)]
    protected string $modified = '';

    #[ORM\ManyToOne(targetEntity: Category::class)]
    #[ORM\JoinColumn(name: 'category_id', referencedColumnName: 'id', nullable: true)]
    protected ?Category $category = null;

    /** @var Collection<int, Channel> */
    #[ORM\ManyToMany(targetEntity: Channel::class)]
    #[ORM\JoinTable(name: 'product_channel')]
    protected Collection $channels;

    public function __construct()
    {
        $this->channels = new ArrayCollection();
    }

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

    public function getPriceInCents(): int
    {
        return $this->priceInCents;
    }

    public function setPriceInCents(int $priceInCents): static
    {
        $this->priceInCents = $priceInCents;

        return $this;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): static
    {
        $this->currency = $currency;

        return $this;
    }

    public function getSupplierTerms(): string
    {
        return $this->supplierTerms;
    }

    public function setSupplierTerms(string $supplierTerms): static
    {
        $this->supplierTerms = $supplierTerms;

        return $this;
    }

    public function getModified(): string
    {
        return $this->modified;
    }

    public function setModified(string $modified): static
    {
        $this->modified = $modified;

        return $this;
    }

    public function getCategory(): ?Category
    {
        return $this->category;
    }

    public function setCategory(?Category $category): static
    {
        $this->category = $category;

        return $this;
    }

    /** @return Collection<int, Channel> */
    public function getChannels(): Collection
    {
        return $this->channels;
    }
}
