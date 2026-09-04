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
final class StorageParityTest extends CatalogueTestCase
{
    #[DataProviderExternal(CatalogueDatabase::class, 'dataProviderEngine')]
    public function testTheTableAndTheJsonlDescribeTheSameHistory(string $environmentVariable): void
    {
        $this->boot($environmentVariable);

        $catalogue = $this->getCatalogue();
        $this->getTransactionProvider()->setUsername('bob');

        $product = $catalogue->createProduct('plane', 4500);
        $catalogue->reprice($product, 4900);

        $rows = $this->readAuditRows('product');
        $lines = $this->readJsonLines();

        static::assertCount(2, $rows);
        static::assertCount(2, $lines);
        static::assertSame(
            \array_column($rows, 'audit_operation'),
            \array_map(static fn(array $line) => $line['entities'][0]['operation'], $lines),
        );

        foreach ($lines as $line) {
            static::assertSame('bob', $line['username']);

            /* the stamp carries its offset, so a purge run in another timezone means the same instant */
            static::assertMatchesRegularExpression(
                '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/',
                $line['date'],
            );
        }

        /*
         * The two shapes differ by design - a column per field against an old/new pair - but never in content. Note
         * the key: the audit table uses the column name, the jsonl record uses the entity's field name.
         */
        static::assertSame(4500, (int)$rows[0]['price_in_cents']);
        static::assertSame(4500, $lines[0]['entities'][0]['columns']['priceInCents']);
        static::assertSame(
            ['old' => 4500, 'new' => 4900],
            $lines[1]['entities'][0]['columns']['priceInCents'],
        );
    }
}
