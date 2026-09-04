<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Storage;

use DateTimeImmutable;
use DateTimeInterface;
use PrecisionSoft\Doctrine\Audit\Contract\StorageInterface;
use PrecisionSoft\Doctrine\Audit\Dto\Storage\StorageDto;
use PrecisionSoft\Doctrine\Audit\Storage\Trait\FileLockTrait;
use PrecisionSoft\Doctrine\Audit\Trait\ThrowTrait;
use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Throwable;

class FileStorage implements StorageInterface
{
    use FileLockTrait;
    use ThrowTrait;

    protected readonly Filesystem $filesystem;
    protected readonly JsonEncoder $jsonEncoder;

    public function __construct(
        protected readonly string $file,
        protected readonly ?LoggerInterface $logger,
    ) {
        $this->filesystem = new Filesystem();
        $this->jsonEncoder = new JsonEncoder();
    }

    public function save(StorageDto $storageDto): void
    {
        if ([] === $storageDto->getEntities() && [] === $storageDto->getCollectionChanges()) {
            return;
        }

        try {
            $transaction = $this->buildTransaction($storageDto);

            $this->appendTransaction($transaction . \PHP_EOL);
        } catch (Throwable $throwable) {
            $this->throw($throwable);
        }
    }

    protected function appendTransaction(string $transaction): void
    {
        $this->filesystem->mkdir(\dirname($this->file));

        $handle = $this->openLocked($this->file, 'ab', \LOCK_EX);

        try {
            $size = $this->getSize($handle);

            try {
                $this->writeAll($handle, $transaction);
                $this->flushAll($handle);
            } catch (Throwable $throwable) {
                /* still under LOCK_EX: a half-written record would make every later read and purge of this file fail on invalid json, including the purge that could repair it */
                \ftruncate($handle, $size);
                $this->flushFile($handle);

                throw $throwable;
            }
        } finally {
            $this->unlock($handle);
        }
    }

    protected function getAuditFile(): string
    {
        return $this->file;
    }

    /**
     * @param resource $handle
     * @return int<0, max>
     */
    protected function getSize($handle): int
    {
        $stat = \fstat($handle);

        /* append mode leaves the pointer at 0 until the first write, so the size cannot be read with `ftell()` */
        return false === $stat ? 0 : \max(0, (int)$stat['size']);
    }

    protected function getLogger(): ?LoggerInterface
    {
        return $this->logger;
    }

    protected function buildTransaction(StorageDto $storageDto): string
    {
        $entities = [];

        foreach ($storageDto->getEntities() as $entityDto) {
            $columns = [];

            foreach ($entityDto->getFields() as $fieldDto) {
                $columns[$fieldDto->getName()] = true === $fieldDto->hasOldValue()
                    ? ['old' => $fieldDto->getOldValue(), 'new' => $fieldDto->getValue()]
                    : $fieldDto->getValue();
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
            'date' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
            'entities' => $entities,
        ];

        if ([] !== $storageDto->getCollectionChanges()) {
            $transaction['collections'] = $storageDto->getCollectionChangesAsArray();
        }

        if ([] !== $transactionDto->getExtras()) {
            $transaction['extras'] = $transactionDto->getExtras();
        }

        return $this->jsonEncoder->encode($transaction, JsonEncoder::FORMAT);
    }
}
