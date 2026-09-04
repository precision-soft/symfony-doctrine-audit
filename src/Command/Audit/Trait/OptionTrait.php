<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Command\Audit\Trait;

use DateTimeImmutable;
use PrecisionSoft\Doctrine\Audit\Dto\Operation;
use PrecisionSoft\Doctrine\Audit\Exception\Exception;
use Symfony\Component\Console\Input\InputInterface;
use Throwable;

trait OptionTrait
{
    protected function getStringOption(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        if (null === $value || '' === $value) {
            return null;
        }

        if (false === \is_string($value)) {
            throw new Exception(\sprintf('option `%s` must be a single value', $name));
        }

        return $value;
    }

    protected function getIntegerOption(InputInterface $input, string $name, int $default): int
    {
        $value = $this->getStringOption($input, $name);

        if (null === $value) {
            return $default;
        }

        if (1 !== \preg_match('/^\d+$/', $value)) {
            throw new Exception(\sprintf('option `%s` must be a positive integer, got `%s`', $name, $value));
        }

        return (int)$value;
    }

    protected function getDateOption(InputInterface $input, string $name): ?DateTimeImmutable
    {
        $value = $this->getStringOption($input, $name);

        if (null === $value) {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Throwable $throwable) {
            throw new Exception(
                \sprintf('option `%s` must be a parsable date, got `%s`', $name, $value),
                0,
                $throwable,
            );
        }
    }

    protected function getOperationOption(InputInterface $input, string $name): ?Operation
    {
        $value = $this->getStringOption($input, $name);

        if (null === $value) {
            return null;
        }

        $operation = Operation::tryFrom($value);

        if (null === $operation) {
            throw new Exception(
                \sprintf(
                    'option `%s` must be one of `%s`, got `%s`',
                    $name,
                    \implode('`, `', Operation::values()),
                    $value,
                ),
            );
        }

        return $operation;
    }

    /** @return array<string, scalar|null> */
    protected function getIdentityOption(InputInterface $input, string $name): array
    {
        $values = $input->getOption($name);

        if (false === \is_array($values)) {
            throw new Exception(\sprintf('option `%s` must be repeatable', $name));
        }

        $identity = [];

        foreach ($values as $value) {
            if (false === \is_string($value) || false === \str_contains($value, '=')) {
                throw new Exception(\sprintf('option `%s` must be given as `field=value`', $name));
            }

            [$field, $rawValue] = \explode('=', $value, 2);

            if ('' === $field) {
                throw new Exception(\sprintf('option `%s` must be given as `field=value`', $name));
            }

            $identity[$field] = $this->castIdentityValue($rawValue);
        }

        return $identity;
    }

    /** The console only ever hands over strings, while a jsonl record keeps the column's json type, and the two are compared strictly. */
    protected function castIdentityValue(string $value): string|int|float|bool|null
    {
        /* the escape hatch for a string column whose value looks like a number or a keyword: `code="007"` stays `007`, where `code=007` would be the integer 7 */
        if (2 <= \strlen($value) && true === \str_starts_with($value, '"') && true === \str_ends_with($value, '"')) {
            return \substr($value, 1, -1);
        }

        return match (true) {
            'null' === $value => null,
            'true' === $value => true,
            'false' === $value => false,
            1 === \preg_match('/^-?\d+$/', $value) => (int)$value,
            true === \is_numeric($value) => (float)$value,
            default => $value,
        };
    }
}
