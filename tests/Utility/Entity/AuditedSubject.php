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
use PrecisionSoft\Doctrine\Audit\Attribute\Ignore;

#[Auditable(true)]
#[ORM\Entity]
#[ORM\Table(name: 'audited_subject')]
class AuditedSubject
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 64)]
    private string $name = '';

    #[Ignore]
    #[ORM\Column(type: 'string', length: 64)]
    private string $secret = '';

    /* excluded through the auditor's global ignored_fields, not through #[Ignore] */
    #[ORM\Column(type: 'string', length: 64)]
    private string $modified = '';

    #[ORM\ManyToOne(targetEntity: RelatedSubject::class)]
    #[ORM\JoinColumn(name: 'related_subject_id', referencedColumnName: 'id', nullable: true)]
    private ?RelatedSubject $related = null;

    /** @var Collection<int, RelatedSubject> */
    #[ORM\ManyToMany(targetEntity: RelatedSubject::class)]
    #[ORM\JoinTable(name: 'audited_subject_related')]
    private Collection $relatedSubjects;

    public function __construct()
    {
        $this->relatedSubjects = new ArrayCollection();
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

    public function getSecret(): string
    {
        return $this->secret;
    }

    public function setSecret(string $secret): static
    {
        $this->secret = $secret;

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

    public function getRelated(): ?RelatedSubject
    {
        return $this->related;
    }

    public function setRelated(?RelatedSubject $related): static
    {
        $this->related = $related;

        return $this;
    }

    /** @return Collection<int, RelatedSubject> */
    public function getRelatedSubjects(): Collection
    {
        return $this->relatedSubjects;
    }
}
