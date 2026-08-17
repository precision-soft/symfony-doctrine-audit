<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Test\Utility;

use Psr\Log\AbstractLogger;
use Stringable;

/** @internal */
final class RecordingLogger extends AbstractLogger
{
    /** @var array<int, array{level: string, message: string, context: array<string, mixed>}> */
    private array $records = [];

    /** @param array<string, mixed> $context */
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => (string)$level,
            'message' => (string)$message,
            'context' => $context,
        ];
    }

    /** @return array<int, array{level: string, message: string, context: array<string, mixed>}> */
    public function getRecords(?string $level = null): array
    {
        if (null === $level) {
            return $this->records;
        }

        return \array_values(\array_filter(
            $this->records,
            static fn(array $record) => $level === $record['level'],
        ));
    }
}
