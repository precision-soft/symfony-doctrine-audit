<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Test\Dto\Auditor;

use PHPUnit\Framework\TestCase;
use PrecisionSoft\Doctrine\Audit\Dto\Auditor\AuditorDto;
use PrecisionSoft\Doctrine\Audit\Dto\Auditor\EntityDto;
use PrecisionSoft\Doctrine\Audit\Dto\Operation;
use stdClass;

/**
 * @internal
 */
final class AuditorDtoTest extends TestCase
{
    public function testGetEntitiesToDelete(): void
    {
        $delete = [new stdClass()];
        $auditorDto = new AuditorDto($delete, [], []);

        static::assertSame($delete, $auditorDto->getEntitiesToDelete());
    }

    public function testGetEntitiesToInsert(): void
    {
        $insert = [new stdClass()];
        $auditorDto = new AuditorDto([], $insert, []);

        static::assertSame($insert, $auditorDto->getEntitiesToInsert());
    }

    public function testGetEntitiesToUpdate(): void
    {
        $update = [new stdClass()];
        $auditorDto = new AuditorDto([], [], $update);

        static::assertSame($update, $auditorDto->getEntitiesToUpdate());
    }

    public function testGetAuditEntitiesEmptyByDefault(): void
    {
        $auditorDto = new AuditorDto([], [], []);

        static::assertSame([], $auditorDto->getAuditEntities());
    }

    public function testAddAuditEntity(): void
    {
        $auditorDto = new AuditorDto([], [], []);
        $entityDto = new EntityDto(Operation::Insert, 'App\\Entity\\User', 'user');

        $result = $auditorDto->addAuditEntity($entityDto);

        static::assertSame($auditorDto, $result);
        static::assertCount(1, $auditorDto->getAuditEntities());
        static::assertSame($entityDto, $auditorDto->getAuditEntities()[0]);
    }

    public function testAddMultipleAuditEntities(): void
    {
        $auditorDto = new AuditorDto([], [], []);
        $userEntityDto = new EntityDto(Operation::Insert, 'App\\Entity\\User', 'user');
        $postEntityDto = new EntityDto(Operation::Delete, 'App\\Entity\\Post', 'post');

        $auditorDto->addAuditEntity($userEntityDto);
        $auditorDto->addAuditEntity($postEntityDto);

        static::assertCount(2, $auditorDto->getAuditEntities());
        static::assertSame($userEntityDto, $auditorDto->getAuditEntities()[0]);
        static::assertSame($postEntityDto, $auditorDto->getAuditEntities()[1]);
    }

    public function testGetEntityChangeSetReturnsChangeSetForTrackedEntity(): void
    {
        $entity = new stdClass();
        $hash = \spl_object_hash($entity);
        $changeSet = ['name' => ['old', 'new']];

        $auditorDto = new AuditorDto([], [], [$entity], [$hash => $changeSet]);

        static::assertSame($changeSet, $auditorDto->getEntityChangeSet($entity));
    }

    public function testGetEntityChangeSetReturnsNullForUntrackedEntity(): void
    {
        $entity = new stdClass();
        $auditorDto = new AuditorDto([], [], []);

        static::assertSame(null, $auditorDto->getEntityChangeSet($entity));
    }

    public function testConstructorWithEmptyChangeSets(): void
    {
        $auditorDto = new AuditorDto([], [], []);

        $entity = new stdClass();
        static::assertSame(null, $auditorDto->getEntityChangeSet($entity));
    }
}
