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
use PrecisionSoft\Doctrine\Audit\Command\DoctrineSchema\UpdateCommand;
use PrecisionSoft\Doctrine\Audit\Contract\AnnotationReadServiceInterface;
use PrecisionSoft\Symfony\Phpunit\MockDto;
use PrecisionSoft\Symfony\Phpunit\TestCase\AbstractTestCase;
use ReflectionMethod;
use stdClass;

/**
 * @internal
 */
final class UpdateCommandTest extends AbstractTestCase
{
    public static function getMockDto(): MockDto
    {
        return new MockDto(stdClass::class);
    }

    public function testGetSchemaSqlDelegatesToUpdateSchemaSql(): void
    {
        $updateCommand = $this->createCommand();

        $metadatas = [];
        $sqlStatements = ['ALTER TABLE audit_user'];

        /** @var SchemaTool&MockInterface $schemaTool */
        $schemaTool = Mockery::mock(SchemaTool::class);
        $schemaTool->shouldReceive('getUpdateSchemaSql')
            ->once()
            ->with($metadatas)
            ->andReturn($sqlStatements);

        $result = $this->invoke($updateCommand, 'getSchemaSql', $schemaTool, $metadatas);

        static::assertSame($sqlStatements, $result);
    }

    public function testExecuteSchemaDelegatesToUpdateSchema(): void
    {
        $updateCommand = $this->createCommand();

        $metadatas = [];

        /** @var SchemaTool&MockInterface $schemaTool */
        $schemaTool = Mockery::mock(SchemaTool::class);
        $schemaTool->shouldReceive('updateSchema')
            ->once()
            ->with($metadatas);

        $this->invoke($updateCommand, 'executeSchema', $schemaTool, $metadatas);

        static::assertSame('audit:doctrine:schema:update', $updateCommand->getName());
    }

    public function testActionAndCompletedVerbs(): void
    {
        $updateCommand = $this->createCommand();

        static::assertSame('updating', $this->invoke($updateCommand, 'getActionVerb'));
        static::assertSame('updated', $this->invoke($updateCommand, 'getCompletedVerb'));
    }

    private function createCommand(): UpdateCommand
    {
        return new UpdateCommand(
            'audit:doctrine:schema:update',
            Mockery::mock(EntityManagerInterface::class),
            Mockery::mock(EntityManagerInterface::class),
            Mockery::mock(AnnotationReadServiceInterface::class),
        );
    }

    private function invoke(UpdateCommand $updateCommand, string $method, mixed ...$arguments): mixed
    {
        $reflectionMethod = new ReflectionMethod($updateCommand, $method);

        return $reflectionMethod->invoke($updateCommand, ...$arguments);
    }
}
