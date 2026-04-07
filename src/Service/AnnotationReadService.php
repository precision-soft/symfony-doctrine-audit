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
    private array $entityDtoCache = [];

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
        return self::resolveEntityClass($entityOrProxy);
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

        if (true === \array_key_exists($className, $this->entityDtoCache)) {
            return $this->entityDtoCache[$className];
        }

        $reflectionClass = $classMetadata->getReflectionClass() ?? new ReflectionClass($className);

        if (false === $this->hasEntityAttribute($reflectionClass)) {
            return $this->entityDtoCache[$className] = null;
        }

        if (false === $this->hasAuditableAttribute($reflectionClass)) {
            return $this->entityDtoCache[$className] = null;
        }

        $ignoredFields = [];

        foreach ($reflectionClass->getProperties() as $reflectionProperty) {
            if (false === $this->hasIgnoreAttribute($reflectionProperty)) {
                continue;
            }

            $field = $reflectionProperty->getName();

            if (true === $classMetadata->isIdentifier($field)) {
                /** @info identifiers are never ignored */
                continue;
            }

            $ignoredFields[] = $field;
        }

        return $this->entityDtoCache[$className] = new EntityDto($className, $ignoredFields);
    }

    private function hasAuditableAttribute(ReflectionClass $reflectionClass): bool
    {
        $attributes = $reflectionClass->getAttributes(Auditable::class);

        if (true === empty($attributes)) {
            return false;
        }

        $auditable = $attributes[0]->newInstance();

        return true === $auditable->enabled;
    }

    private function hasEntityAttribute(ReflectionClass $reflectionClass): bool
    {
        $attributes = $reflectionClass->getAttributes(Entity::class);

        return false === empty($attributes);
    }

    private function hasIgnoreAttribute(ReflectionProperty $reflectionProperty): bool
    {
        $attributes = $reflectionProperty->getAttributes(Ignore::class);

        if (true === empty($attributes)) {
            return false;
        }

        $ignore = $attributes[0]->newInstance();

        return true === $ignore->enabled;
    }
}
