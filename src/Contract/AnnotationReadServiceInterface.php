<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Contract;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\Mapping\ClassMetadata;
use PrecisionSoft\Doctrine\Audit\Dto\Annotation\EntityDto;

interface AnnotationReadServiceInterface
{
    /** @return EntityDto[] */
    public function read(EntityManagerInterface $entityManager): array;

    public function buildEntityDto(ClassMetadata $classMetadata): ?EntityDto;

    public function getEntityClass(object $entityOrProxy): string;
}
