<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Test\Utility\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;
use PrecisionSoft\Doctrine\Audit\Test\Utility\Entity\ValueObject\SubjectReference;

/** The mapping every uid package ships: a column that hydrates into a value object, so the entity's identifier is an object. */
class SubjectReferenceType extends Type
{
    public const NAME = 'subject_reference';

    public function getName(): string
    {
        return static::NAME;
    }

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getStringTypeDeclarationSQL(['length' => 64]);
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        return null === $value ? null : (string)$value;
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?SubjectReference
    {
        if (null === $value || true === $value instanceof SubjectReference) {
            return $value;
        }

        return new SubjectReference((string)$value);
    }
}
