<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Test\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata as MappingClassMetadata;
use Doctrine\ORM\Mapping\ClassMetadataFactory;
use Doctrine\Persistence\Mapping\ClassMetadata;
use Mockery;
use Mockery\MockInterface;
use PrecisionSoft\Doctrine\Audit\Dto\Annotation\EntityDto;
use PrecisionSoft\Doctrine\Audit\Service\AnnotationReadService;
use PrecisionSoft\Doctrine\Audit\Test\Entity\OneEntity;
use PrecisionSoft\Doctrine\Audit\Test\Entity\TwoEntity;
use PrecisionSoft\Symfony\Phpunit\MockDto;
use PrecisionSoft\Symfony\Phpunit\TestCase\AbstractTestCase;
use ReflectionClass;

/**
 * @internal
 */
final class AnnotationReadServiceTest extends AbstractTestCase
{
    public static function getMockDto(): MockDto
    {
        return new MockDto(
            AnnotationReadService::class,
            [],
            true,
        );
    }

    public function testGetEntityClassReturnsClassName(): void
    {
        $entity = new OneEntity();

        static::assertSame(OneEntity::class, AnnotationReadService::getEntityClass($entity));
    }

    public function testBuildEntityDtoReturnsNullForNonAuditableEntity(): void
    {
        $mock = $this->get(AnnotationReadService::class);

        $nonAuditableMetadata = new MappingClassMetadata(\stdClass::class);

        $result = $mock->buildEntityDto($nonAuditableMetadata);

        static::assertNull($result);
    }

    public function testBuildEntityDtoCachesResult(): void
    {
        $mock = $this->get(AnnotationReadService::class);

        $classMetadata = new MappingClassMetadata(OneEntity::class);

        $first = $mock->buildEntityDto($classMetadata);
        $second = $mock->buildEntityDto($classMetadata);

        static::assertSame($first, $second);
    }

    public function testBuildEntityDto()
    {
        $entities = [
            OneEntity::class => [],
            TwoEntity::class => ['id', 'description'],
        ];

        /** @var AnnotationReadService|MockInterface $mock */
        $mock = $this->get(AnnotationReadService::class);

        foreach ($entities as $entity => $ignoredFields) {
            $classMetadata = new MappingClassMetadata($entity);

            $entityDto = $mock->buildEntityDto($classMetadata);

            static::assertSame($entity, $entityDto->getClass());
            static::assertSame($ignoredFields, $entityDto->getIgnoredFields());
        }
    }

    public function testRead(): void
    {
        $metadataOne = Mockery::mock(ClassMetadata::class);
        $metadataOne->shouldReceive('getReflectionClass')
            ->once()
            ->andReturn(new ReflectionClass(new OneEntity()));
        $metadataOne->shouldReceive('getName')
            ->once()
            ->andReturn(OneEntity::class);

        $metadataTwo = Mockery::mock(ClassMetadata::class);
        $metadataTwo->shouldReceive('getReflectionClass')
            ->once()
            ->andReturn(new ReflectionClass(new TwoEntity()));
        $metadataTwo->shouldReceive('getName')
            ->once()
            ->andReturn(TwoEntity::class);
        $metadataTwo->shouldReceive('isIdentifier')
            ->once()
            ->with('id')
            ->andReturn(true);
        $metadataTwo->shouldReceive('isIdentifier')
            ->once()
            ->with('description')
            ->andReturn(false);

        /** @var AnnotationReadService|MockInterface $mock */
        $mock = $this->get(AnnotationReadService::class);

        $classMetadataFactoryMock = Mockery::mock(ClassMetadataFactory::class);

        $entityManagerInterfaceMock = Mockery::mock(EntityManagerInterface::class);
        $entityManagerInterfaceMock->shouldReceive('getMetadataFactory')
            ->once()
            ->andReturn($classMetadataFactoryMock);
        $classMetadataFactoryMock->shouldReceive('getAllMetadata')
            ->once()
            ->andReturn([$metadataOne, $metadataTwo]);

        $entityDtos = $mock->read($entityManagerInterfaceMock);

        static::assertIsArray($entityDtos);

        foreach ($entityDtos as $entityDto) {
            $expected = TwoEntity::class === $entityDto->getClass() ? 1 : 0;

            static::assertInstanceOf(EntityDto::class, $entityDto);
            static::assertSame($expected, \count($entityDto->getIgnoredFields()));
        }
    }
}
