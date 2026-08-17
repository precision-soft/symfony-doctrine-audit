<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Test\Functional;

use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use PrecisionSoft\Doctrine\Audit\Test\Utility\AuditIntegrationEnvironment;
use PrecisionSoft\Doctrine\Audit\Test\Utility\Entity\AuditedSubject;
use PrecisionSoft\Doctrine\Audit\Test\Utility\Entity\CarVehicle;
use PrecisionSoft\Doctrine\Audit\Test\Utility\Entity\CircleShape;
use PrecisionSoft\Doctrine\Audit\Test\Utility\Entity\InheritingSubject;
use PrecisionSoft\Doctrine\Audit\Test\Utility\Entity\RelatedSubject;
use PrecisionSoft\Doctrine\Audit\Test\Utility\Entity\SquareShape;
use PrecisionSoft\Doctrine\Audit\Test\Utility\Entity\UnauditedSubject;
use PrecisionSoft\Doctrine\Audit\Test\Utility\IntegrationDatabase;
use PrecisionSoft\Doctrine\Audit\Test\Utility\SkipIntegrationException;

/** @internal */
#[Group('integration')]
final class AuditFunctionalTest extends TestCase
{
    private ?AuditIntegrationEnvironment $environment = null;

    protected function tearDown(): void
    {
        $this->environment?->close();
        $this->environment = null;

        parent::tearDown();
    }

    #[DataProviderExternal(IntegrationDatabase::class, 'dataProviderEngine')]
    public function testInsertWritesOneAuditRowAndOneTransaction(string $environmentVariable): void
    {
        $environment = $this->createEnvironment($environmentVariable);

        $subject = (new AuditedSubject())
            ->setName('first')
            ->setSecret('do-not-audit')
            ->setModified('2026-08-15');

        $environment->sourceEntityManager->persist($subject);
        $environment->sourceEntityManager->flush();

        $transactions = $environment->readTransactions();
        static::assertCount(1, $transactions);
        static::assertSame('integration', $transactions[0]['username']);

        $auditRows = $environment->readAuditRows('audited_subject');
        static::assertCount(1, $auditRows);

        $auditRow = $auditRows[0];
        static::assertSame('insert', $auditRow['audit_operation']);
        static::assertSame('first', $auditRow['name']);
        static::assertSame((int)$transactions[0]['id'], (int)$auditRow['audit_transaction_id']);

        static::assertSame($subject->getId(), (int)$auditRow['id']);

        static::assertArrayNotHasKey('secret', $auditRow);
        static::assertArrayNotHasKey('modified', $auditRow);
    }

    #[DataProviderExternal(IntegrationDatabase::class, 'dataProviderEngine')]
    public function testUpdateAuditsTheNewValueAndDeleteAuditsTheLastKnownOne(string $environmentVariable): void
    {
        $environment = $this->createEnvironment($environmentVariable);

        $subject = (new AuditedSubject())->setName('before')->setSecret('s')->setModified('m');
        $environment->sourceEntityManager->persist($subject);
        $environment->sourceEntityManager->flush();

        $subject->setName('after');
        $environment->sourceEntityManager->flush();

        $environment->sourceEntityManager->remove($subject);
        $environment->sourceEntityManager->flush();

        $auditRows = $environment->readAuditRows('audited_subject');
        static::assertCount(3, $auditRows);

        static::assertSame(['insert', 'update', 'delete'], \array_column($auditRows, 'audit_operation'));

        static::assertSame('after', $auditRows[1]['name']);
        static::assertSame('after', $auditRows[2]['name']);

        static::assertCount(3, $environment->readTransactions());
    }

    #[DataProviderExternal(IntegrationDatabase::class, 'dataProviderEngine')]
    public function testOwningSideAssociationIsAuditedByItsJoinColumn(string $environmentVariable): void
    {
        $environment = $this->createEnvironment($environmentVariable);

        $related = (new RelatedSubject())->setLabel('target');
        $environment->sourceEntityManager->persist($related);
        $environment->sourceEntityManager->flush();

        $subject = (new AuditedSubject())->setName('with-relation')->setSecret('s')->setModified('m')->setRelated($related);
        $environment->sourceEntityManager->persist($subject);
        $environment->sourceEntityManager->flush();

        $auditRows = $environment->readAuditRows('audited_subject');
        static::assertCount(1, $auditRows);
        static::assertSame($related->getId(), (int)$auditRows[0]['related_subject_id']);

        static::assertNotContains(
            'related_subject',
            IntegrationDatabase::listTables($environment->auditEntityManager->getConnection()),
        );
    }

    #[DataProviderExternal(IntegrationDatabase::class, 'dataProviderEngine')]
    public function testAnUnauditedEntityProducesNothing(string $environmentVariable): void
    {
        $environment = $this->createEnvironment($environmentVariable);

        $environment->sourceEntityManager->persist((new UnauditedSubject())->setName('invisible'));
        $environment->sourceEntityManager->flush();

        static::assertSame([], $environment->readTransactions());
    }

    #[DataProviderExternal(IntegrationDatabase::class, 'dataProviderEngine')]
    public function testSingleTableInheritanceWritesTheDiscriminator(string $environmentVariable): void
    {
        $environment = $this->createEnvironment($environmentVariable);

        $circle = (new CircleShape())->setRadius(3);
        $circle->setName('round');

        $environment->sourceEntityManager->persist($circle);
        $environment->sourceEntityManager->flush();

        $auditRows = $environment->readAuditRows('shape');
        static::assertCount(1, $auditRows);
        static::assertSame('insert', $auditRows[0]['audit_operation']);
        static::assertSame('round', $auditRows[0]['name']);
        static::assertSame('circle', $auditRows[0]['shape_kind']);
    }

    #[DataProviderExternal(IntegrationDatabase::class, 'dataProviderEngine')]
    public function testAnInheritedAuditableCanBeSwitchedOffOnAChild(string $environmentVariable): void
    {
        $environment = $this->createEnvironment($environmentVariable);

        $square = (new SquareShape())->setSide(4);
        $square->setName('boxy');

        $environment->sourceEntityManager->persist($square);
        $environment->sourceEntityManager->flush();

        static::assertSame([], $environment->readAuditRows('shape'));
        static::assertSame([], $environment->readTransactions());

        /* the opt-out is per class, not per hierarchy: the sibling is still audited */
        $circle = (new CircleShape())->setRadius(1);
        $circle->setName('round');

        $environment->sourceEntityManager->persist($circle);
        $environment->sourceEntityManager->flush();

        static::assertCount(1, $environment->readAuditRows('shape'));
    }

    #[DataProviderExternal(IntegrationDatabase::class, 'dataProviderEngine')]
    public function testJoinedInheritanceAuditsBothTheChildAndTheRootTable(string $environmentVariable): void
    {
        $environment = $this->createEnvironment($environmentVariable);

        $car = (new CarVehicle())->setDoors(5);
        $car->setName('estate');

        $environment->sourceEntityManager->persist($car);
        $environment->sourceEntityManager->flush();

        $childRows = $environment->readAuditRows('car_vehicle');
        static::assertCount(1, $childRows);
        static::assertSame(5, (int)$childRows[0]['doors']);

        $rootRows = $environment->readAuditRows('vehicle');
        static::assertCount(1, $rootRows);
        static::assertSame('estate', $rootRows[0]['name']);
        static::assertSame('car', $rootRows[0]['vehicle_kind']);

        static::assertSame(
            (int)$childRows[0]['audit_transaction_id'],
            (int)$rootRows[0]['audit_transaction_id'],
        );
    }

    #[DataProviderExternal(IntegrationDatabase::class, 'dataProviderEngine')]
    public function testIgnoredPrivateMappedSuperclassFieldIsNeverWritten(string $environmentVariable): void
    {
        $environment = $this->createEnvironment($environmentVariable);

        $subject = (new InheritingSubject())->setName('child');
        $subject->setPassword('correct-horse')->setEmail('someone@example.com');

        $environment->sourceEntityManager->persist($subject);
        $environment->sourceEntityManager->flush();

        $auditRows = $environment->readAuditRows('inheriting_subject');
        static::assertCount(1, $auditRows);
        static::assertSame('someone@example.com', $auditRows[0]['email']);
        static::assertArrayNotHasKey('password', $auditRows[0]);
    }

    #[DataProviderExternal(IntegrationDatabase::class, 'dataProviderEngine')]
    public function testTwoFlushesInOneRequestProduceTwoIndependentTransactions(string $environmentVariable): void
    {
        $environment = $this->createEnvironment($environmentVariable);

        $environment->sourceEntityManager->persist(
            (new AuditedSubject())->setName('one')->setSecret('s')->setModified('m'),
        );
        $environment->sourceEntityManager->flush();

        $environment->sourceEntityManager->persist(
            (new AuditedSubject())->setName('two')->setSecret('s')->setModified('m'),
        );
        $environment->sourceEntityManager->flush();

        $transactions = $environment->readTransactions();
        static::assertCount(2, $transactions);

        $auditRows = $environment->readAuditRows('audited_subject');
        static::assertCount(2, $auditRows);
        static::assertSame(['one', 'two'], \array_column($auditRows, 'name'));

        static::assertNotSame(
            (int)$auditRows[0]['audit_transaction_id'],
            (int)$auditRows[1]['audit_transaction_id'],
        );
    }

    #[DataProviderExternal(IntegrationDatabase::class, 'dataProviderEngine')]
    public function testTransactionExtrasAreStored(string $environmentVariable): void
    {
        $environment = $this->createEnvironment($environmentVariable, ['request_id' => 'abc-123']);

        $environment->sourceEntityManager->persist(
            (new AuditedSubject())->setName('with-extras')->setSecret('s')->setModified('m'),
        );
        $environment->sourceEntityManager->flush();

        $transactions = $environment->readTransactions();
        static::assertCount(1, $transactions);
        static::assertSame('{"request_id":"abc-123"}', $transactions[0]['extras']);
    }

    /** @param array<string, mixed> $extras */
    private function createEnvironment(string $environmentVariable, array $extras = []): AuditIntegrationEnvironment
    {
        try {
            $sourceConnection = IntegrationDatabase::createConnection(
                $environmentVariable,
                IntegrationDatabase::SOURCE_SCHEMA,
            );
            $auditConnection = IntegrationDatabase::createConnection(
                $environmentVariable,
                IntegrationDatabase::AUDIT_SCHEMA,
            );
        } catch (SkipIntegrationException $skipIntegrationException) {
            static::markTestSkipped($skipIntegrationException->getMessage());
        }

        return $this->environment = new AuditIntegrationEnvironment(
            $sourceConnection,
            $auditConnection,
            extras: $extras,
        );
    }
}
