<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Example\Service;

use PrecisionSoft\Doctrine\Audit\Contract\TransactionProviderInterface;
use PrecisionSoft\Doctrine\Audit\Dto\Storage\TransactionDto;

/**
 * What an application has to supply: who is changing the catalogue, and whatever else the trail should be able to
 * answer for. A real one reads the security token and the request id; this one is told.
 */
class CatalogueTransactionProvider implements TransactionProviderInterface
{
    protected string $username = 'catalogue-manager';

    /** @var array<string, mixed> */
    protected array $extras = [];

    public function getTransaction(): TransactionDto
    {
        return new TransactionDto($this->username, $this->extras);
    }

    public function setUsername(string $username): static
    {
        $this->username = $username;

        return $this;
    }

    /** @param array<string, mixed> $extras */
    public function setExtras(array $extras): static
    {
        $this->extras = $extras;

        return $this;
    }
}
