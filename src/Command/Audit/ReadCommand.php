<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Command\Audit;

use PrecisionSoft\Doctrine\Audit\Command\Audit\Trait\OptionTrait;
use PrecisionSoft\Doctrine\Audit\Contract\AuditReaderInterface;
use PrecisionSoft\Doctrine\Audit\Dto\Query\AuditQuery;
use PrecisionSoft\Symfony\Console\Command\AbstractCommand as ConsoleAbstractCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

class ReadCommand extends ConsoleAbstractCommand
{
    use OptionTrait;

    protected const ENTITY_CLASS = 'entity-class';
    protected const IDENTITY = 'identity';
    protected const FROM = 'from';
    protected const UNTIL = 'until';
    protected const USERNAME = 'username';
    protected const OPERATION = 'operation';
    protected const LIMIT = 'limit';
    protected const CURSOR = 'cursor';

    public function __construct(string $name, protected readonly AuditReaderInterface $auditReader)
    {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setDescription('read audit transactions from the corresponding storage')
            ->addOption(static::ENTITY_CLASS, null, InputOption::VALUE_REQUIRED, 'audited entity class')
            ->addOption(
                static::IDENTITY,
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'audited column as `field=value`, repeatable; `null`, `true`, `false` and numbers are cast',
            )
            ->addOption(static::FROM, null, InputOption::VALUE_REQUIRED, 'earliest transaction date, inclusive')
            ->addOption(static::UNTIL, null, InputOption::VALUE_REQUIRED, 'latest transaction date, exclusive')
            ->addOption(static::USERNAME, null, InputOption::VALUE_REQUIRED, 'transaction username')
            ->addOption(static::OPERATION, null, InputOption::VALUE_REQUIRED, 'delete, insert or update')
            ->addOption(
                static::LIMIT,
                null,
                InputOption::VALUE_REQUIRED,
                'transactions per page',
                (string)AuditQuery::DEFAULT_LIMIT,
            )
            ->addOption(static::CURSOR, null, InputOption::VALUE_REQUIRED, 'cursor of a previous page');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $auditPage = $this->auditReader->read(new AuditQuery(
                entityClass: $this->getStringOption($input, static::ENTITY_CLASS),
                identity: $this->getIdentityOption($input, static::IDENTITY),
                from: $this->getDateOption($input, static::FROM),
                until: $this->getDateOption($input, static::UNTIL),
                username: $this->getStringOption($input, static::USERNAME),
                operation: $this->getOperationOption($input, static::OPERATION),
                limit: $this->getIntegerOption($input, static::LIMIT, AuditQuery::DEFAULT_LIMIT),
                cursor: $this->getStringOption($input, static::CURSOR),
            ));

            $rows = [];

            foreach ($auditPage->getTransactions() as $transaction) {
                $rows[] = [
                    $this->describeValue($transaction['date'] ?? null),
                    $this->describeValue($transaction['username'] ?? null),
                    $this->describeEntities($transaction),
                    $this->describeCollections($transaction),
                ];
            }

            $this->style->table(['date', 'username', 'entities', 'collections'], $rows);

            $this->info(\sprintf('%d transaction(s)', \count($auditPage->getTransactions())));

            if (null !== $auditPage->getNextCursor()) {
                $this->info(\sprintf('next page: --%s=%s', static::CURSOR, $auditPage->getNextCursor()));
            }
        } catch (Throwable $throwable) {
            $this->error($throwable->getMessage(), $throwable, true);

            return static::FAILURE;
        }

        return static::SUCCESS;
    }

    /** @param array<string, mixed> $transaction */
    protected function describeEntities(array $transaction): string
    {
        $descriptions = [];

        foreach ($this->getRows($transaction, 'entities') as $entity) {
            $descriptions[] = \sprintf(
                '%s %s',
                $this->describeValue($entity['operation'] ?? null),
                $this->describeValue($entity['class'] ?? null),
            );
        }

        return \implode(\PHP_EOL, $descriptions);
    }

    /** @param array<string, mixed> $transaction */
    protected function describeCollections(array $transaction): string
    {
        $descriptions = [];

        foreach ($this->getRows($transaction, 'collections') as $collection) {
            $descriptions[] = \sprintf(
                '%s::%s +%d -%d',
                $this->describeValue($collection['owner_class'] ?? null),
                $this->describeValue($collection['field'] ?? null),
                $this->countValues($collection['added'] ?? null),
                $this->countValues($collection['removed'] ?? null),
            );
        }

        return \implode(\PHP_EOL, $descriptions);
    }

    /**
     * A record is whatever the storage wrote, so every level is checked before it is walked.
     *
     * @param array<string, mixed> $transaction
     * @return list<array<string, mixed>>
     */
    protected function getRows(array $transaction, string $key): array
    {
        $rows = true === \is_array($transaction[$key] ?? null) ? $transaction[$key] : [];

        return \array_values(\array_filter($rows, static fn(mixed $row): bool => true === \is_array($row)));
    }

    protected function describeValue(mixed $value): string
    {
        return true === \is_scalar($value) ? (string)$value : '';
    }

    protected function countValues(mixed $value): int
    {
        return true === \is_array($value) ? \count($value) : 0;
    }
}
