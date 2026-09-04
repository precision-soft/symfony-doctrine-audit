<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Example\Test\Functional;

use PHPUnit\Framework\Attributes\DataProviderExternal;
use PrecisionSoft\Doctrine\Audit\Example\Test\Utility\CatalogueDatabase;
use PrecisionSoft\Doctrine\Audit\Example\Test\Utility\CatalogueTestCase;

/** @internal */
final class ProductAuditTest extends CatalogueTestCase
{
    #[DataProviderExternal(CatalogueDatabase::class, 'dataProviderEngine')]
    public function testTheLifeOfAProductIsAnswerableFromTheTrail(string $environmentVariable): void
    {
        $this->boot($environmentVariable);

        $catalogue = $this->getCatalogue();
        $this->getTransactionProvider()->setUsername('alice')->setExtras(['request_id' => 'req-1']);

        $category = $catalogue->createCategory('tools');
        $product = $catalogue->createProduct('hammer', 1250, $category);
        $catalogue->reprice($product, 1490);
        $catalogue->retire($product);

        $transactions = $this->readTransactions();

        static::assertCount(4, $transactions, 'the category, the product, the reprice and the retirement');
        static::assertSame('alice', $transactions[0]['username']);
        static::assertSame('{"request_id":"req-1"}', $transactions[0]['extras']);

        $rows = $this->readAuditRows('product');

        static::assertCount(3, $rows);
        static::assertSame(['insert', 'update', 'delete'], \array_column($rows, 'audit_operation'));
        static::assertSame('hammer', $rows[0]['name']);
        static::assertSame(1250, (int)$rows[0]['price_in_cents']);
        static::assertSame(1490, (int)$rows[1]['price_in_cents']);

        /* a delete keeps the last known values, which is the only reason the trail can answer for a row that is gone */
        static::assertSame(1490, (int)$rows[2]['price_in_cents']);
        static::assertSame((int)$category->getId(), (int)$rows[2]['category_id']);
    }

    #[DataProviderExternal(CatalogueDatabase::class, 'dataProviderEngine')]
    public function testTheTwoIgnoreMechanismsKeepTheirColumnsOutOfTheTrail(string $environmentVariable): void
    {
        $this->boot($environmentVariable);

        $catalogue = $this->getCatalogue();
        $catalogue->createProduct('anvil', 9900);

        /* the audit table has no column at all for either: the schema follows the ignore rules, not only the writes */
        $columns = \array_keys($this->readAuditRows('product')[0]);

        static::assertContains('name', $columns);
        static::assertNotContains('supplier_terms', $columns, 'excluded by the attribute');
        static::assertNotContains('modified', $columns, 'excluded by the auditor configuration');

        /* the jsonl record keys by field name, so the check names the property, not the column */
        $line = $this->readJsonLines()[0];

        static::assertArrayHasKey('name', $line['entities'][0]['columns']);
        static::assertArrayNotHasKey('supplierTerms', $line['entities'][0]['columns']);
        static::assertArrayNotHasKey('modified', $line['entities'][0]['columns']);
    }

    #[DataProviderExternal(CatalogueDatabase::class, 'dataProviderEngine')]
    public function testTheTrailNeverHoldsTheCatalogueTables(string $environmentVariable): void
    {
        $this->boot($environmentVariable);

        $this->getCatalogue()->createProduct('mallet', 2200);

        $tables = $this->listTrailTables();

        static::assertContains('audit_transaction', $tables);
        static::assertContains('product', $tables);

        /* the join table carries no transaction id, so it is not an audit table and the audit schema drops it */
        static::assertNotContains('product_channel', $tables);

        /* an opted-out class gets no audit table either */
        static::assertNotContains('bundle_offer', $tables);
    }
}
