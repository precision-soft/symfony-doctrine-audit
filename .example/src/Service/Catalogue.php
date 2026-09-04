<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Example\Service;

use Doctrine\ORM\EntityManagerInterface;
use PrecisionSoft\Doctrine\Audit\Example\Entity\Category;
use PrecisionSoft\Doctrine\Audit\Example\Entity\Channel;
use PrecisionSoft\Doctrine\Audit\Example\Entity\Product;
use PrecisionSoft\Doctrine\Audit\Example\Exception\Exception;

/**
 * The nomenclator's operations. Nothing here knows about auditing: the trail is written by the flush events, which is
 * the whole point of the bundle - an application keeps its own code and gets a trail.
 */
class Catalogue
{
    public function __construct(protected readonly EntityManagerInterface $entityManager) {}

    public function createCategory(string $name): Category
    {
        $category = (new Category())->setName($name);

        $this->entityManager->persist($category);
        $this->entityManager->flush();

        return $category;
    }

    public function createChannel(string $code): Channel
    {
        $channel = (new Channel())->setCode($code);

        $this->entityManager->persist($channel);
        $this->entityManager->flush();

        return $channel;
    }

    public function createProduct(string $name, int $priceInCents, ?Category $category = null): Product
    {
        $product = (new Product())
            ->setName($name)
            ->setPriceInCents($priceInCents)
            ->setSupplierTerms('net 30, confidential')
            ->setModified('2026-09-03')
            ->setCategory($category);

        $this->entityManager->persist($product);
        $this->entityManager->flush();

        return $product;
    }

    public function reprice(Product $product, int $priceInCents): Product
    {
        if (0 > $priceInCents) {
            throw new Exception(\sprintf('a price cannot be negative, got %d', $priceInCents));
        }

        $product->setPriceInCents($priceInCents);
        $this->entityManager->flush();

        return $product;
    }

    public function publishOn(Product $product, Channel ...$channels): Product
    {
        foreach ($channels as $channel) {
            $product->getChannels()->add($channel);
        }

        $this->entityManager->flush();

        return $product;
    }

    public function withdrawFrom(Product $product, Channel $channel): Product
    {
        $product->getChannels()->removeElement($channel);
        $this->entityManager->flush();

        return $product;
    }

    public function retire(Product $product): void
    {
        $this->entityManager->remove($product);
        $this->entityManager->flush();
    }
}
