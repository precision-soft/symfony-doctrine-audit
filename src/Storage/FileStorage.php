<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Storage;

use DateTime;
use PrecisionSoft\Doctrine\Audit\Contract\StorageInterface;
use PrecisionSoft\Doctrine\Audit\Dto\Storage\StorageDto;
use PrecisionSoft\Doctrine\Audit\Trait\ThrowTrait;
use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Throwable;

final class FileStorage implements StorageInterface
{
    use ThrowTrait;

    public function __construct(
        private readonly string $file,
        private readonly ?LoggerInterface $logger,
    ) {}

    public function save(StorageDto $storageDto): void
    {
        try {
            $transaction = $this->buildTransaction($storageDto);

            $filesystem = new Filesystem();

            $filesystem->appendToFile($this->file, $transaction . \PHP_EOL);
        } catch (Throwable $t) {
            $this->throw($t);
        }
    }

    private function buildTransaction(StorageDto $storageDto): string
    {
        $entities = [];

        foreach ($storageDto->getEntities() as $entityDto) {
            $columns = [];

            foreach ($entityDto->getFields() as $columnDto) {
                $columns[$columnDto->getName()] = null !== $columnDto->getOldValue()
                    ? ['old' => $columnDto->getOldValue(), 'new' => $columnDto->getValue()]
                    : $columnDto->getValue();
            }

            $entities[] = [
                'operation' => $entityDto->getOperation()->value,
                'class' => $entityDto->getClass(),
                'columns' => $columns,
            ];
        }

        $transactionDto = $storageDto->getTransaction();

        $transaction = [
            'username' => $transactionDto->getUsername(),
            'date' => (new DateTime())->format('Y-m-d H:i:s'),
            'entities' => $entities,
        ];

        if (false === empty($transactionDto->getExtras())) {
            $transaction['extras'] = $transactionDto->getExtras();
        }

        return (new JsonEncoder())->encode($transaction, JsonEncoder::FORMAT);
    }
}
