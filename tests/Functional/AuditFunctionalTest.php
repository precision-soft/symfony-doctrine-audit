<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Test\Functional;

use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use PrecisionSoft\Doctrine\Audit\Exception\Exception;
use PrecisionSoft\Doctrine\Audit\Test\Utility\AuditIntegrationEnvironment;
use PrecisionSoft\Doctrine\Audit\Test\Utility\Entity\AuditedSubject;
use PrecisionSoft\Doctrine\Audit\Test\Utility\Entity\CarVehicle;
use PrecisionSoft\Doctrine\Audit\Test\Utility\Entity\CircleShape;
use PrecisionSoft\Doctrine\Audit\Test\Utility\Entity\InheritingSubject;
use PrecisionSoft\Doctrine\Audit\Test\Utility\Entity\RelatedSubject;
use PrecisionSoft\Doctrine\Audit\Test\Utility\Entity\SquareShape;
use PrecisionSoft\Doctrine\Audit\Test\Utility\Entity\SubjectNote;
use PrecisionSoft\Doctrine\Audit\Test\Utility\Entity\TaggedSubject;
use PrecisionSoft\Doctrine\Audit\Test\Utility\Entity\UnauditedSubject;
use PrecisionSoft\Doctrine\Audit\Test\Utility\Entity\ValueObject\SubjectReference;
use PrecisionSoft\Doctrine\Audit\Test\Utility\Entity\VanVehicle;
use PrecisionSoft\Doctrine\Audit\Test\Utility\IntegrationDatabase;
use PrecisionSoft\Doctrine\Audit\Test\Utility\SkipIntegrationException;

/** @internal */
#[Group('integration')]
final class AuditFunctionalTest extends TestCase
{
    private ?AuditIntegrationEnvironment $environment = null;

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
    public function testOwningCollectionChangesUseDeterministicIdentifiers(string $environmentVariable): void
    {
        $environment = $this->createEnvironment($environmentVariable);

        $firstRelated = (new RelatedSubject())->setLabel('first');
        $secondRelated = (new RelatedSubject())->setLabel('second');
        $environment->sourceEntityManager->persist($firstRelated);
        $environment->sourceEntityManager->persist($secondRelated);
        $environment->sourceEntityManager->flush();

        $subject = (new AuditedSubject())
            ->setName('collections')
            ->setSecret('s')
            ->setModified('m');
        $environment->sourceEntityManager->persist($subject);
        $environment->sourceEntityManager->flush();

        $subject->getRelatedSubjects()->add($secondRelated);
        $subject->getRelatedSubjects()->add($firstRelated);
        $environment->sourceEntityManager->flush();

        $subject->getRelatedSubjects()->removeElement($firstRelated);
        $environment->sourceEntityManager->flush();

        $transactions = $environment->readTransactions();
        static::assertCount(3, $transactions);
        static::assertNull($transactions[0]['collection_changes']);

        $additionChanges = \json_decode(
            $transactions[1]['collection_changes'],
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );
        static::assertSame(AuditedSubject::class, $additionChanges[0]['owner_class']);
        static::assertSame(['id' => $subject->getId()], $additionChanges[0]['owner_identifier']);
        static::assertSame('relatedSubjects', $additionChanges[0]['field']);
        static::assertSame(RelatedSubject::class, $additionChanges[0]['target_class']);
        static::assertSame(
            [
                ['id' => $firstRelated->getId()],
                ['id' => $secondRelated->getId()],
            ],
            $additionChanges[0]['added'],
        );
        static::assertSame([], $additionChanges[0]['removed']);

        $removalChanges = \json_decode(
            $transactions[2]['collection_changes'],
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );
        static::assertSame([], $removalChanges[0]['added']);
        static::assertSame([['id' => $firstRelated->getId()]], $removalChanges[0]['removed']);
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

    #[DataProviderExternal(IntegrationDatabase::class, 'dataProviderEngine')]
    public function testDeletingAnOwnerAuditsTheRemovalOfItsCollection(string $environmentVariable): void
    {
        $environment = $this->createEnvironment($environmentVariable);

        $firstRelated = (new RelatedSubject())->setLabel('first');
        $secondRelated = (new RelatedSubject())->setLabel('second');
        $environment->sourceEntityManager->persist($firstRelated);
        $environment->sourceEntityManager->persist($secondRelated);

        $subject = (new AuditedSubject())->setName('doomed')->setSecret('s')->setModified('m');
        $subject->getRelatedSubjects()->add($firstRelated);
        $subject->getRelatedSubjects()->add($secondRelated);
        $environment->sourceEntityManager->persist($subject);
        $environment->sourceEntityManager->flush();

        $subjectId = $subject->getId();
        $firstRelatedId = $firstRelated->getId();
        $secondRelatedId = $secondRelated->getId();

        /* the collection is never touched after the reload, so it reaches the flush uninitialised - the shape a real delete has */
        $environment->sourceEntityManager->clear();

        $reloaded = $environment->sourceEntityManager->find(AuditedSubject::class, $subjectId);
        static::assertNotNull($reloaded);

        $environment->sourceEntityManager->remove($reloaded);
        $environment->sourceEntityManager->flush();

        $transactions = $environment->readTransactions();
        $deletion = \end($transactions);

        static::assertIsArray($deletion);
        static::assertNotNull($deletion['collection_changes'], 'the join rows the delete removed must be audited');

        $changes = \json_decode((string)$deletion['collection_changes'], true, 512, \JSON_THROW_ON_ERROR);

        static::assertCount(1, $changes);
        static::assertSame(AuditedSubject::class, $changes[0]['owner_class']);
        static::assertSame('relatedSubjects', $changes[0]['field']);
        static::assertSame([], $changes[0]['added']);
        static::assertSame(
            [['id' => $firstRelatedId], ['id' => $secondRelatedId]],
            $changes[0]['removed'],
        );
    }

    #[DataProviderExternal(IntegrationDatabase::class, 'dataProviderEngine')]
    public function testClearingACollectionAuditsEveryRowItRemoves(string $environmentVariable): void
    {
        $environment = $this->createEnvironment($environmentVariable);

        $firstRelated = (new RelatedSubject())->setLabel('first');
        $secondRelated = (new RelatedSubject())->setLabel('second');
        $environment->sourceEntityManager->persist($firstRelated);
        $environment->sourceEntityManager->persist($secondRelated);

        $subject = (new AuditedSubject())->setName('cleared')->setSecret('s')->setModified('m');
        $subject->getRelatedSubjects()->add($firstRelated);
        $subject->getRelatedSubjects()->add($secondRelated);
        $environment->sourceEntityManager->persist($subject);
        $environment->sourceEntityManager->flush();

        /* `clear()` empties the collection and only then takes its snapshot, so the set it is about to remove lives in the database alone */
        $subject->getRelatedSubjects()->clear();
        $environment->sourceEntityManager->flush();

        $transactions = $environment->readTransactions();
        $clearing = \end($transactions);

        static::assertIsArray($clearing);
        static::assertNotNull($clearing['collection_changes']);

        $changes = \json_decode((string)$clearing['collection_changes'], true, 512, \JSON_THROW_ON_ERROR);

        static::assertCount(1, $changes);
        static::assertSame([], $changes[0]['added']);
        static::assertSame(
            [['id' => $firstRelated->getId()], ['id' => $secondRelated->getId()]],
            $changes[0]['removed'],
        );
    }

    #[DataProviderExternal(IntegrationDatabase::class, 'dataProviderEngine')]
    public function testAJoinedChildThatOptsOutWritesNeitherTable(string $environmentVariable): void
    {
        $environment = $this->createEnvironment($environmentVariable);

        $van = (new VanVehicle())->setPayload(1200);
        $van->setName('panel');

        $environment->sourceEntityManager->persist($van);
        $environment->sourceEntityManager->flush();

        static::assertSame([], $environment->readTransactions());
        static::assertSame([], $environment->readAuditRows('vehicle'), 'the opted-out child must not audit its root table either');

        /* the audit schema follows the same rule: an opted-out class gets no audit table */
        static::assertNotContains('van_vehicle', IntegrationDatabase::listTables($environment->auditEntityManager->getConnection()));

        /* and the sibling that did not opt out is still audited through both tables */
        $car = (new CarVehicle())->setDoors(3);
        $car->setName('hatch');

        $environment->sourceEntityManager->persist($car);
        $environment->sourceEntityManager->flush();

        static::assertCount(1, $environment->readAuditRows('car_vehicle'));
        static::assertCount(1, $environment->readAuditRows('vehicle'));
    }

    #[DataProviderExternal(IntegrationDatabase::class, 'dataProviderEngine')]
    public function testAStringableIdentifierIsRecordedAsAString(string $environmentVariable): void
    {
        $environment = $this->createEnvironment($environmentVariable);

        $related = (new RelatedSubject())->setLabel('tag');
        $environment->sourceEntityManager->persist($related);

        $subject = (new TaggedSubject(new SubjectReference('01JC9Z0000000000000000')))->setName('uid-keyed');
        $environment->sourceEntityManager->persist($subject);
        $environment->sourceEntityManager->flush();

        $subject->getRelatedSubjects()->add($related);
        $environment->sourceEntityManager->flush();

        $transactions = $environment->readTransactions();
        $addition = \end($transactions);

        static::assertIsArray($addition);
        static::assertNotNull($addition['collection_changes']);

        $changes = \json_decode((string)$addition['collection_changes'], true, 512, \JSON_THROW_ON_ERROR);

        static::assertSame(TaggedSubject::class, $changes[0]['owner_class']);
        static::assertSame(['reference' => '01JC9Z0000000000000000'], $changes[0]['owner_identifier']);
        static::assertSame([['id' => $related->getId()]], $changes[0]['added']);
    }

    #[DataProviderExternal(IntegrationDatabase::class, 'dataProviderEngine')]
    public function testAnUnrenderableIdentifierIsRefusedBeforeTheFlushCommits(string $environmentVariable): void
    {
        $environment = $this->createEnvironment($environmentVariable);

        $related = (new RelatedSubject())->setLabel('tag');
        $environment->sourceEntityManager->persist($related);

        $environment->sourceEntityManager->flush();

        $subjectNote = (new SubjectNote($related))->setNote('unrenderable');
        $environment->sourceEntityManager->persist($subjectNote);
        $environment->sourceEntityManager->flush();

        $subjectNote->getRelatedSubjects()->add($related);

        try {
            $environment->sourceEntityManager->flush();

            static::fail('an identifier the audit trail cannot render must be refused');
        } catch (Exception $exception) {
            static::assertStringContainsString('is not scalar', $exception->getMessage());
        }

        /* onFlush runs before the orm opens its transaction, so refusing there keeps the join row out of the database instead of losing its audit row after the commit */
        static::assertSame(
            0,
            (int)$environment->sourceEntityManager->getConnection()
                ->fetchOne('SELECT COUNT(*) FROM subject_note_related'),
        );
    }

    protected function tearDown(): void
    {
        $this->environment?->close();
        $this->environment = null;

        parent::tearDown();
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
