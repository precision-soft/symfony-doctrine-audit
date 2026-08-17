<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Test\Command\DoctrineSchema;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Mockery;
use Mockery\MockInterface;
use PrecisionSoft\Doctrine\Audit\Command\DoctrineSchema\CreateCommand;
use PrecisionSoft\Doctrine\Audit\Contract\AnnotationReadServiceInterface;
use PrecisionSoft\Symfony\Phpunit\MockDto;
use PrecisionSoft\Symfony\Phpunit\TestCase\AbstractTestCase;
use ReflectionMethod;
use stdClass;

/**
 * @internal
 */
final class CreateCommandTest extends AbstractTestCase
{
    public static function getMockDto(): MockDto
    {
        return new MockDto(stdClass::class);
    }

    public function testGetSchemaSqlDelegatesToCreateSchemaSql(): void
    {
        $createCommand = $this->createCommand();

        $metadatas = [];
        $sqlStatements = ['CREATE TABLE audit_user'];

        /** @var SchemaTool&MockInterface $schemaTool */
        $schemaTool = Mockery::mock(SchemaTool::class);
        $schemaTool->shouldReceive('getCreateSchemaSql')
            ->once()
            ->with($metadatas)
            ->andReturn($sqlStatements);

        $result = $this->invoke($createCommand, 'getSchemaSql', $schemaTool, $metadatas);

        static::assertSame($sqlStatements, $result);
    }

    public function testExecuteSchemaDelegatesToCreateSchema(): void
    {
        $createCommand = $this->createCommand();

        $metadatas = [];

        /** @var SchemaTool&MockInterface $schemaTool */
        $schemaTool = Mockery::mock(SchemaTool::class);
        $schemaTool->shouldReceive('createSchema')
            ->once()
            ->with($metadatas);

        $this->invoke($createCommand, 'executeSchema', $schemaTool, $metadatas);

        static::assertSame('audit:doctrine:schema:create', $createCommand->getName());
    }

    public function testActionAndCompletedVerbs(): void
    {
        $createCommand = $this->createCommand();

        static::assertSame('creating', $this->invoke($createCommand, 'getActionVerb'));
        static::assertSame('created', $this->invoke($createCommand, 'getCompletedVerb'));
    }

    private function createCommand(): CreateCommand
    {
        return new CreateCommand(
            'audit:doctrine:schema:create',
            Mockery::mock(EntityManagerInterface::class),
            Mockery::mock(EntityManagerInterface::class),
            Mockery::mock(AnnotationReadServiceInterface::class),
        );
    }

    private function invoke(CreateCommand $createCommand, string $method, mixed ...$arguments): mixed
    {
        $reflectionMethod = new ReflectionMethod($createCommand, $method);

        return $reflectionMethod->invoke($createCommand, ...$arguments);
    }
}
