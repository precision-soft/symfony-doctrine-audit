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

/** Identified by an association, the one identifier shape the audit trail still cannot render: the value is an entity, and no string form of it would be stable. */
#[Auditable(true)]
#[ORM\Entity]
#[ORM\Table(name: 'subject_note')]
class SubjectNote
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: RelatedSubject::class)]
    #[ORM\JoinColumn(name: 'subject_id', referencedColumnName: 'id')]
    private RelatedSubject $subject;

    #[ORM\Column(type: 'string', length: 64)]
    private string $note = '';

    /** @var Collection<int, RelatedSubject> */
    #[ORM\ManyToMany(targetEntity: RelatedSubject::class)]
    #[ORM\JoinTable(name: 'subject_note_related')]
    #[ORM\JoinColumn(name: 'subject_note_id', referencedColumnName: 'subject_id')]
    #[ORM\InverseJoinColumn(name: 'related_subject_id', referencedColumnName: 'id')]
    private Collection $relatedSubjects;

    public function __construct(RelatedSubject $subject)
    {
        $this->subject = $subject;
        $this->relatedSubjects = new ArrayCollection();
    }

    public function getSubject(): RelatedSubject
    {
        return $this->subject;
    }

    public function getNote(): string
    {
        return $this->note;
    }

    public function setNote(string $note): static
    {
        $this->note = $note;

        return $this;
    }

    /** @return Collection<int, RelatedSubject> */
    public function getRelatedSubjects(): Collection
    {
        return $this->relatedSubjects;
    }
}
