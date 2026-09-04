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
use Doctrine\Persistence\Mapping\RuntimeReflectionService;
use Mockery;
use PrecisionSoft\Doctrine\Audit\Dto\Annotation\EntityDto;
use PrecisionSoft\Doctrine\Audit\Service\AnnotationReadService;
use PrecisionSoft\Doctrine\Audit\Test\Entity\OneEntity;
use PrecisionSoft\Doctrine\Audit\Test\Entity\TwoEntity;
use PrecisionSoft\Doctrine\Audit\Test\Utility\Entity\AuditedSubject;
use PrecisionSoft\Doctrine\Audit\Test\Utility\Entity\CarVehicle;
use PrecisionSoft\Doctrine\Audit\Test\Utility\Entity\CircleShape;
use PrecisionSoft\Doctrine\Audit\Test\Utility\Entity\InheritingSubject;
use PrecisionSoft\Doctrine\Audit\Test\Utility\Entity\SquareShape;
use PrecisionSoft\Symfony\Phpunit\MockDto;
use PrecisionSoft\Symfony\Phpunit\TestCase\AbstractTestCase;
use Proxies\__CG__\PrecisionSoft\Doctrine\Audit\Test\Utility\Entity\AuditedSubject as AuditedSubjectProxy;
use ReflectionClass;
use stdClass;

/**
 * @internal
 */
final class AnnotationReadServiceTest extends AbstractTestCase
{
    private AnnotationReadService $annotationReadService;

    public static function getMockDto(): MockDto
    {
        return new MockDto(stdClass::class);
    }

    public function testGetEntityClassReturnsClassName(): void
    {
        $entity = new OneEntity();

        static::assertSame(OneEntity::class, $this->annotationReadService->getEntityClass($entity));
    }

    public function testBuildEntityDtoReturnsNullForNonAuditableEntity(): void
    {
        $nonAuditableMetadata = new MappingClassMetadata(stdClass::class);
        $nonAuditableMetadata->initializeReflection(new RuntimeReflectionService());

        $entityDto = $this->annotationReadService->buildEntityDto($nonAuditableMetadata);

        static::assertSame(null, $entityDto);
    }

    public function testBuildEntityDtoCachesResult(): void
    {
        $classMetadata = new MappingClassMetadata(OneEntity::class);
        $classMetadata->initializeReflection(new RuntimeReflectionService());

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
            $classMetadata->initializeReflection(new RuntimeReflectionService());

            $entityDto = $this->annotationReadService->buildEntityDto($classMetadata);

            static::assertNotNull($entityDto);
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

        /* counted rather than type-checked: an empty result would leave the loop below asserting nothing */
        static::assertCount(2, $entityDtos);

        foreach ($entityDtos as $entityDto) {
            $expected = TwoEntity::class === $entityDto->getClass() ? 1 : 0;

            static::assertInstanceOf(EntityDto::class, $entityDto);
            static::assertSame($expected, \count($entityDto->getIgnoredFields()));
        }
    }

    public function testGetEntityClassStripsTheDoctrineProxyMarker(): void
    {
        static::assertSame(
            AuditedSubject::class,
            $this->annotationReadService->getEntityClass(new AuditedSubjectProxy()),
        );
    }

    public function testAuditableIsInheritedAndCanBeSwitchedOffOnAChild(): void
    {
        static::assertNotNull($this->buildEntityDtoFor(CircleShape::class));

        static::assertNull($this->buildEntityDtoFor(SquareShape::class));

        static::assertNotNull($this->buildEntityDtoFor(CarVehicle::class));
    }

    public function testIgnoreIsReadFromAPrivateMappedSuperclassProperty(): void
    {
        $entityDto = $this->buildEntityDtoFor(InheritingSubject::class);

        static::assertNotNull($entityDto);
        static::assertSame(['password'], $entityDto->getIgnoredFields());
    }

    protected function setUp(): void
    {
        $this->annotationReadService = new AnnotationReadService();
    }

    /** @param class-string $class */
    private function buildEntityDtoFor(string $class): ?EntityDto
    {
        $classMetadata = new MappingClassMetadata($class);
        $classMetadata->initializeReflection(new RuntimeReflectionService());

        return $this->annotationReadService->buildEntityDto($classMetadata);
    }
}
