<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Example\Test\Functional;

use PHPUnit\Framework\Attributes\DataProviderExternal;
use PrecisionSoft\Doctrine\Audit\Example\Entity\Product;
use PrecisionSoft\Doctrine\Audit\Example\Test\Utility\CatalogueDatabase;
use PrecisionSoft\Doctrine\Audit\Example\Test\Utility\CatalogueTestCase;

/** @internal */
final class CollectionAuditTest extends CatalogueTestCase
{
    #[DataProviderExternal(CatalogueDatabase::class, 'dataProviderEngine')]
    public function testPublishingAndWithdrawingAChannelIsInTheTransactionPayload(string $environmentVariable): void
    {
        $this->boot($environmentVariable);

        $catalogue = $this->getCatalogue();
        $web = $catalogue->createChannel('web');
        $shop = $catalogue->createChannel('shop');
        $product = $catalogue->createProduct('drill', 8900);

        /* added in the reverse order on purpose: the payload is sorted, so two trails of the same change compare equal */
        $catalogue->publishOn($product, $shop, $web);
        $catalogue->withdrawFrom($product, $web);

        $transactions = $this->readTransactions();
        $publish = $this->decodeCollectionChanges($transactions[\count($transactions) - 2]);
        $withdraw = $this->decodeCollectionChanges($transactions[\count($transactions) - 1]);

        static::assertCount(1, $publish);
        static::assertSame(Product::class, $publish[0]['owner_class']);
        static::assertSame(['id' => $product->getId()], $publish[0]['owner_identifier']);
        static::assertSame('channels', $publish[0]['field']);
        static::assertSame(
            [['id' => $web->getId()], ['id' => $shop->getId()]],
            $publish[0]['added'],
            'the identifiers are ordered, not in the order the code happened to add them',
        );
        static::assertSame([], $publish[0]['removed']);

        static::assertSame([], $withdraw[0]['added']);
        static::assertSame([['id' => $web->getId()]], $withdraw[0]['removed']);
    }

    #[DataProviderExternal(CatalogueDatabase::class, 'dataProviderEngine')]
    public function testRetiringAProductAuditsTheChannelsItLeaves(string $environmentVariable): void
    {
        $this->boot($environmentVariable);

        $catalogue = $this->getCatalogue();
        $web = $catalogue->createChannel('web');
        $shop = $catalogue->createChannel('shop');
        $product = $catalogue->createProduct('sander', 12900);
        $catalogue->publishOn($product, $web, $shop);

        $entityManager = $this->getCatalogueEntityManager();
        $productId = $product->getId();

        /* the collection is never touched after the reload, so the retirement sees it exactly as a real request would */
        $entityManager->clear();

        $reloaded = $entityManager->find(Product::class, $productId);
        static::assertInstanceOf(Product::class, $reloaded);

        $catalogue->retire($reloaded);

        $transactions = $this->readTransactions();
        $retirement = $this->decodeCollectionChanges($transactions[\count($transactions) - 1]);

        static::assertCount(1, $retirement, 'the join rows the retirement removed are part of its trail');
        static::assertSame([], $retirement[0]['added']);
        static::assertSame(
            [['id' => $web->getId()], ['id' => $shop->getId()]],
            $retirement[0]['removed'],
        );
    }

    #[DataProviderExternal(CatalogueDatabase::class, 'dataProviderEngine')]
    public function testClearingTheChannelsAuditsEveryRowItRemoves(string $environmentVariable): void
    {
        $this->boot($environmentVariable);

        $catalogue = $this->getCatalogue();
        $web = $catalogue->createChannel('web');
        $product = $catalogue->createProduct('router', 15900);
        $catalogue->publishOn($product, $web);

        $product->getChannels()->clear();
        $this->getCatalogueEntityManager()->flush();

        $transactions = $this->readTransactions();
        $clearing = $this->decodeCollectionChanges($transactions[\count($transactions) - 1]);

        static::assertSame([['id' => $web->getId()]], $clearing[0]['removed']);
    }

    /**
     * @param array<string, mixed> $transaction
     * @return array<int, array<string, mixed>>
     */
    private function decodeCollectionChanges(array $transaction): array
    {
        static::assertNotNull($transaction['collection_changes']);

        return \json_decode((string)$transaction['collection_changes'], true, 512, \JSON_THROW_ON_ERROR);
    }
}
