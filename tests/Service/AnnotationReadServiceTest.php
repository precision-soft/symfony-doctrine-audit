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
use PrecisionSoft\Symfony\Phpunit\MockDto;
use PrecisionSoft\Symfony\Phpunit\TestCase\AbstractTestCase;
use PrecisionSoft\Doctrine\Audit\Dto\Annotation\EntityDto;
use PrecisionSoft\Doctrine\Audit\Service\AnnotationReadService;
use PrecisionSoft\Doctrine\Audit\Test\Entity\OneEntity;
use PrecisionSoft\Doctrine\Audit\Test\Entity\TwoEntity;
use ReflectionClass;
use stdClass;

/**
 * @internal
 */
final class AnnotationReadServiceTest extends AbstractTestCase
{
    public static function getMockDto(): MockDto
    {
        return new MockDto(stdClass::class);
    }

    private AnnotationReadService $annotationReadService;

    protected function setUp(): void
    {
        $this->annotationReadService = new AnnotationReadService();
    }

    public function testGetEntityClassReturnsClassName(): void
    {
        $entity = new OneEntity();

        static::assertSame(OneEntity::class, $this->annotationReadService->getEntityClass($entity));
    }

    public function testBuildEntityDtoReturnsNullForNonAuditableEntity(): void
    {
        $nonAuditableMetadata = new MappingClassMetadata(stdClass::class);

        $entityDto = $this->annotationReadService->buildEntityDto($nonAuditableMetadata);

        static::assertSame(null, $entityDto);
    }

    public function testBuildEntityDtoCachesResult(): void
    {
        $classMetadata = new MappingClassMetadata(OneEntity::class);

        $firstEntityDto = $this->annotationReadService->buildEntityDto($classMetadata);
        $secondEntityDto = $this->annotationReadService->buildEntityDto($classMetadata);

        static::assertSame($firstEntityDto, $secondEntityDto);
    }

    public function testBuildEntityDto(): void
    {
        $entities = [
            OneEntity::class => [],
            TwoEntity::class => ['id', 'description'],
        ];

        foreach ($entities as $entity => $ignoredFields) {
            $classMetadata = new MappingClassMetadata($entity);

            $entityDto = $this->annotationReadService->buildEntityDto($classMetadata);

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

        $classMetadataFactoryMock = Mockery::mock(ClassMetadataFactory::class);

        $entityManagerInterfaceMock = Mockery::mock(EntityManagerInterface::class);
        $entityManagerInterfaceMock->shouldReceive('getMetadataFactory')
            ->once()
            ->andReturn($classMetadataFactoryMock);
        $classMetadataFactoryMock->shouldReceive('getAllMetadata')
            ->once()
            ->andReturn([$metadataOne, $metadataTwo]);

        $entityDtos = $this->annotationReadService->read($entityManagerInterfaceMock);

        static::assertIsArray($entityDtos);

        foreach ($entityDtos as $entityDto) {
            $expected = TwoEntity::class === $entityDto->getClass() ? 1 : 0;

            static::assertInstanceOf(EntityDto::class, $entityDto);
            static::assertSame($expected, \count($entityDto->getIgnoredFields()));
        }
    }
}
