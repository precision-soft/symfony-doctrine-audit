<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\Persistence\Mapping\ClassMetadata;
use PrecisionSoft\Doctrine\Audit\Attribute\Auditable;
use PrecisionSoft\Doctrine\Audit\Attribute\Ignore;
use PrecisionSoft\Doctrine\Audit\Dto\Annotation\EntityDto;
use PrecisionSoft\Doctrine\Audit\Exception\Exception;
use ReflectionClass;
use ReflectionProperty;

class AnnotationReadService
{
    /** @var array<string, EntityDto|null> */
    private array $entityDtoCache = [];

    public static function getEntityClass(object $class): string
    {
        $class = \is_object($class) ? $class::class : $class;

        $proxyString = '\\__CG__\\';
        $proxyPosition = \mb_strrpos($class, $proxyString);
        if (false !== $proxyPosition) {
            return \mb_substr($class, $proxyPosition + \strlen($proxyString));
        }

        return $class;
    }

    /** @return EntityDto[] */
    public function read(EntityManagerInterface $entityManager): array
    {
        $entities = [];

        $metadatas = $entityManager->getMetadataFactory()->getAllMetadata();

        foreach ($metadatas as $metadata) {
            $entityDto = $this->buildEntityDto($metadata);

            if (null === $entityDto) {
                continue;
            }

            $entityClass = $entityDto->getClass();

            if (true === isset($entities[$entityClass])) {
                throw new Exception(
                    \sprintf('duplicate annotation for entity class `%s`', $entityClass),
                );
            }

            $entities[$entityClass] = $entityDto;
        }

        return $entities;
    }

    public function buildEntityDto(ClassMetadata $classMetadata): ?EntityDto
    {
        $className = $classMetadata->getName();

        if (\array_key_exists($className, $this->entityDtoCache)) {
            return $this->entityDtoCache[$className];
        }

        $reflectionClass = $classMetadata->getReflectionClass() ?? new ReflectionClass($className);

        if (false === $this->isEntity($reflectionClass)) {
            /* ignore non entity */
            return $this->entityDtoCache[$className] = null;
        }

        if (false === $this->isAuditable($reflectionClass)) {
            /* ignore not auditable entity */
            return $this->entityDtoCache[$className] = null;
        }

        $ignoredFields = [];

        foreach ($reflectionClass->getProperties() as $reflectionProperty) {
            if (false === $this->isIgnored($reflectionProperty)) {
                continue;
            }

            $field = $reflectionProperty->getName();

            if (true === $classMetadata->isIdentifier($field)) {
                /* identifiers are never ignored */
                continue;
            }

            $ignoredFields[] = $field;
        }

        return $this->entityDtoCache[$className] = new EntityDto($className, $ignoredFields);
    }

    private function isAuditable(ReflectionClass $reflectionClass): bool
    {
        $attributes = $reflectionClass->getAttributes(Auditable::class);
        foreach ($attributes as $attribute) {
            /** @var Auditable $auditable */
            $auditable = $attribute->newInstance();

            return $auditable->enabled;
        }

        return false;
    }

    private function isEntity(ReflectionClass $reflectionClass): bool
    {
        $attributes = $reflectionClass->getAttributes(Entity::class);

        return false === empty($attributes);
    }

    private function isIgnored(ReflectionProperty $reflectionProperty): bool
    {
        $attributes = $reflectionProperty->getAttributes(Ignore::class);
        foreach ($attributes as $attribute) {
            /** @var Ignore $ignore */
            $ignore = $attribute->newInstance();

            return $ignore->enabled;
        }

        return false;
    }
}
