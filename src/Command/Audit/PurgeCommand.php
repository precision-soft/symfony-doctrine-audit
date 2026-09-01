<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Command\Audit;

use PrecisionSoft\Doctrine\Audit\Command\Audit\Trait\OptionTrait;
use PrecisionSoft\Doctrine\Audit\Contract\AuditPurgerInterface;
use PrecisionSoft\Doctrine\Audit\Dto\Query\PurgeRequest;
use PrecisionSoft\Doctrine\Audit\Exception\Exception;
use PrecisionSoft\Symfony\Console\Command\AbstractCommand as ConsoleAbstractCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

class PurgeCommand extends ConsoleAbstractCommand
{
    use OptionTrait;

    protected const BEFORE = 'before';
    protected const BATCH_SIZE = 'batch-size';
    protected const FORCE = 'force';

    public function __construct(string $name, protected readonly AuditPurgerInterface $auditPurger)
    {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setDescription('purge whole audit transactions older than a date, one bounded batch per run')
            ->addOption(static::BEFORE, null, InputOption::VALUE_REQUIRED, 'purge transactions older than this date')
            ->addOption(
                static::BATCH_SIZE,
                null,
                InputOption::VALUE_REQUIRED,
                'transactions purged per run',
                (string)PurgeRequest::DEFAULT_BATCH_SIZE,
            )
            ->addOption(static::FORCE, null, InputOption::VALUE_NONE, 'purge for real instead of reporting');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $before = $this->getDateOption($input, static::BEFORE);

            if (null === $before) {
                throw new Exception(\sprintf('option `%s` is mandatory', static::BEFORE));
            }

            $dryRun = false === $input->getOption(static::FORCE);

            if (false === $dryRun) {
                $this->warning('careful when running this in a production environment');
            }

            $purgeResult = $this->auditPurger->purge(new PurgeRequest(
                $before,
                $this->getIntegerOption($input, static::BATCH_SIZE, PurgeRequest::DEFAULT_BATCH_SIZE),
                $dryRun,
            ));

            $this->style->table(
                ['matched', 'purged', 'more batches'],
                [[
                    (string)$purgeResult->getMatchedTransactions(),
                    (string)$purgeResult->getPurgedTransactions(),
                    true === $purgeResult->hasMore() ? 'yes' : 'no',
                ]],
            );

            if (true === $dryRun) {
                $this->info(\sprintf('nothing was purged; pass --%s to purge', static::FORCE));

                return static::SUCCESS;
            }

            $this->success(\sprintf('purged %d transaction(s)', $purgeResult->getPurgedTransactions()));

            if (true === $purgeResult->hasMore()) {
                $this->info('run the command again to purge the next batch');
            }
        } catch (Throwable $throwable) {
            $this->error($throwable->getMessage(), $throwable, true);

            return static::FAILURE;
        }

        return static::SUCCESS;
    }
}
