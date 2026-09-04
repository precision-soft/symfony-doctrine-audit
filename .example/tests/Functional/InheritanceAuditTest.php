<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Example\Test\Functional;

use PHPUnit\Framework\Attributes\DataProviderExternal;
use PrecisionSoft\Doctrine\Audit\Example\Entity\BundleOffer;
use PrecisionSoft\Doctrine\Audit\Example\Entity\DiscountOffer;
use PrecisionSoft\Doctrine\Audit\Example\Test\Utility\CatalogueDatabase;
use PrecisionSoft\Doctrine\Audit\Example\Test\Utility\CatalogueTestCase;

/** @internal */
final class InheritanceAuditTest extends CatalogueTestCase
{
    #[DataProviderExternal(CatalogueDatabase::class, 'dataProviderEngine')]
    public function testADiscountIsAuditedThroughItsOwnTableAndTheRoot(string $environmentVariable): void
    {
        $this->boot($environmentVariable);

        $entityManager = $this->getCatalogueEntityManager();
        $offer = (new DiscountOffer())->setPercentage(15);
        $offer->setLabel('autumn sale');

        $entityManager->persist($offer);
        $entityManager->flush();

        $childRows = $this->readAuditRows('discount_offer');
        static::assertCount(1, $childRows);
        static::assertSame(15, (int)$childRows[0]['percentage']);

        $rootRows = $this->readAuditRows('offer');
        static::assertCount(1, $rootRows);
        static::assertSame('autumn sale', $rootRows[0]['label']);
        static::assertSame('discount', $rootRows[0]['offer_kind']);

        /* one flush is one transaction, so both halves of the entity carry the same transaction id */
        static::assertSame(
            (int)$childRows[0]['audit_transaction_id'],
            (int)$rootRows[0]['audit_transaction_id'],
        );
    }

    #[DataProviderExternal(CatalogueDatabase::class, 'dataProviderEngine')]
    public function testAChildThatOptsOutIsAbsentFromTheTrailEntirely(string $environmentVariable): void
    {
        $this->boot($environmentVariable);

        $entityManager = $this->getCatalogueEntityManager();
        $offer = (new BundleOffer())->setItemCount(3);
        $offer->setLabel('starter kit');

        $entityManager->persist($offer);
        $entityManager->flush();

        static::assertSame([], $this->readTransactions());
        static::assertSame([], $this->readAuditRows('offer'), 'the opt-out reaches the root table too');
        static::assertNotContains('bundle_offer', $this->listTrailTables());
    }
}
