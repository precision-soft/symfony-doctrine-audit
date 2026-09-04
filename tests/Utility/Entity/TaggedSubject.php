<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Test\Utility\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use PrecisionSoft\Doctrine\Audit\Attribute\Auditable;
use PrecisionSoft\Doctrine\Audit\Test\Utility\Entity\ValueObject\SubjectReference;

/** Owns a collection whose own identifier is a value object, the shape every `Uuid`/`Ulid` mapping has. */
#[Auditable(true)]
#[ORM\Entity]
#[ORM\Table(name: 'tagged_subject')]
class TaggedSubject
{
    #[ORM\Id]
    #[ORM\Column(type: 'subject_reference', length: 64)]
    private SubjectReference $reference;

    #[ORM\Column(type: 'string', length: 64)]
    private string $name = '';

    /** @var Collection<int, RelatedSubject> */
    #[ORM\ManyToMany(targetEntity: RelatedSubject::class)]
    #[ORM\JoinTable(name: 'tagged_subject_related')]
    #[ORM\JoinColumn(name: 'tagged_subject_reference', referencedColumnName: 'reference')]
    #[ORM\InverseJoinColumn(name: 'related_subject_id', referencedColumnName: 'id')]
    private Collection $relatedSubjects;

    public function __construct(SubjectReference $reference)
    {
        $this->reference = $reference;
        $this->relatedSubjects = new ArrayCollection();
    }

    public function getReference(): SubjectReference
    {
        return $this->reference;
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

    /** @return Collection<int, RelatedSubject> */
    public function getRelatedSubjects(): Collection
    {
        return $this->relatedSubjects;
    }
}
