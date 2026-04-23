<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Contract;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\Mapping\ClassMetadata;
use PrecisionSoft\Doctrine\Audit\Dto\Annotation\EntityDto;
use PrecisionSoft\Doctrine\Audit\Exception\Exception;
use ReflectionException;

interface AnnotationReadServiceInterface
{
    /**
     * @return EntityDto[]
     *
     * @throws Exception if a duplicate @Auditable annotation is found for the same entity class
     */
    public function read(EntityManagerInterface $entityManager): array;

    /**
     * @phpstan-param ClassMetadata<object> $classMetadata
     * @throws ReflectionException if the entity class cannot be reflected (e.g. class does not exist)
     */
    public function buildEntityDto(ClassMetadata $classMetadata): ?EntityDto;

    public function getEntityClass(object $entityOrProxy): string;
}
