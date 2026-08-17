<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\Persistence\Mapping\ClassMetadata;
use Doctrine\Persistence\Proxy;
use PrecisionSoft\Doctrine\Audit\Attribute\Auditable;
use PrecisionSoft\Doctrine\Audit\Attribute\Ignore;
use PrecisionSoft\Doctrine\Audit\Contract\AnnotationReadServiceInterface;
use PrecisionSoft\Doctrine\Audit\Dto\Annotation\EntityDto;
use PrecisionSoft\Doctrine\Audit\Exception\Exception;
use ReflectionClass;
use ReflectionProperty;

class AnnotationReadService implements AnnotationReadServiceInterface
{
    /** @var array<string, EntityDto|null> */
    protected array $entityDtoCache = [];

    public static function resolveEntityClass(object $entityOrProxy): string
    {
        $className = $entityOrProxy::class;

        $proxyMarker = '\\' . Proxy::MARKER . '\\';
        $proxyPosition = \mb_strrpos($className, $proxyMarker);
        if (false !== $proxyPosition) {
            return \mb_substr($className, $proxyPosition + \mb_strlen($proxyMarker));
        }

        return $className;
    }

    public function getEntityClass(object $entityOrProxy): string
    {
        return static::resolveEntityClass($entityOrProxy);
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

    /** @phpstan-param ClassMetadata<object> $classMetadata */
    public function buildEntityDto(ClassMetadata $classMetadata): ?EntityDto
    {
        $className = $classMetadata->getName();

        if (true === \array_key_exists($className, $this->entityDtoCache)) {
            return $this->entityDtoCache[$className];
        }

        /** @var ReflectionClass<object> $reflectionClass */
        $reflectionClass = $classMetadata->getReflectionClass();

        if (false === $this->hasEntityAttribute($reflectionClass)) {
            return $this->entityDtoCache[$className] = null;
        }

        if (false === $this->hasAuditableAttribute($reflectionClass)) {
            return $this->entityDtoCache[$className] = null;
        }

        return $this->entityDtoCache[$className] = new EntityDto(
            $className,
            $this->readIgnoredFields($reflectionClass, $classMetadata),
        );
    }

    /**
     * The hierarchy is walked because `getProperties()` does not return a parent's private properties, while Doctrine merges a mapped superclass's fields into the child's metadata; the most-derived declaration wins.
     *
     * @phpstan-param ClassMetadata<object> $classMetadata
     * @param ReflectionClass<object> $reflectionClass
     * @return string[]
     */
    protected function readIgnoredFields(ReflectionClass $reflectionClass, ClassMetadata $classMetadata): array
    {
        $ignoredFields = [];
        $visitedFields = [];

        $currentClass = $reflectionClass;

        while (false !== $currentClass) {
            foreach ($currentClass->getProperties() as $reflectionProperty) {
                $field = $reflectionProperty->getName();

                if (true === isset($visitedFields[$field])) {
                    continue;
                }

                $visitedFields[$field] = true;

                if (false === $this->hasIgnoreAttribute($reflectionProperty)) {
                    continue;
                }

                if (true === $classMetadata->isIdentifier($field)) {
                    continue;
                }

                $ignoredFields[] = $field;
            }

            $currentClass = $currentClass->getParentClass();
        }

        return $ignoredFields;
    }

    /**
     * The hierarchy is walked because `getAttributes()` does not return a parent's attributes; the walk stops at the nearest declaration whatever it says, which is what makes `#[Auditable(false)]` on a child an opt-out.
     *
     * @param ReflectionClass<object> $reflectionClass
     */
    protected function hasAuditableAttribute(ReflectionClass $reflectionClass): bool
    {
        $currentClass = $reflectionClass;

        while (false !== $currentClass) {
            $attributes = $currentClass->getAttributes(Auditable::class);

            if ([] !== $attributes) {
                return true === $attributes[0]->newInstance()->enabled;
            }

            $currentClass = $currentClass->getParentClass();
        }

        return false;
    }

    /** @param ReflectionClass<object> $reflectionClass */
    protected function hasEntityAttribute(ReflectionClass $reflectionClass): bool
    {
        $attributes = $reflectionClass->getAttributes(Entity::class);

        return [] !== $attributes;
    }

    protected function hasIgnoreAttribute(ReflectionProperty $reflectionProperty): bool
    {
        $attributes = $reflectionProperty->getAttributes(Ignore::class);

        if ([] === $attributes) {
            return false;
        }

        $ignore = $attributes[0]->newInstance();

        return true === $ignore->enabled;
    }
}
