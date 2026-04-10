# Symfony Doctrine Audit

[![PHP >= 8.2](https://img.shields.io/badge/php-%3E%3D8.2-8892BF)](https://www.php.net/)
[![PHPStan Level 8](https://img.shields.io/badge/phpstan-level%208-brightgreen)](https://phpstan.org/)
[![Code Style PER-CS2.0](https://img.shields.io/badge/code%20style-PER--CS2.0-blue)](https://www.php-fig.org/per/coding-style/)
[![License MIT](https://img.shields.io/badge/license-MIT-green)](LICENSE)

**You may fork and modify it as you wish.**

Any suggestions are welcomed.

## Requirements

- PHP >= 8.2
- Symfony 7.*
- Doctrine ORM 3.*
- Doctrine DBAL 4.*

## Installation

```shell
composer require precision-soft/symfony-doctrine-audit
```

Register the bundle in `config/bundles.php` (if not auto-discovered):

```php
return [
    PrecisionSoft\Doctrine\Audit\PrecisionSoftDoctrineAuditBundle::class => ['all' => true],
];
```

## How it works

The library hooks into Doctrine's `onFlush` and `postFlush` events to capture entity changes automatically:

1. **Entity detection** -- Mark entities for auditing with the `#[Auditable]` PHP attribute. Individual fields can be excluded with `#[Ignore]`.
2. **Change capture** -- During Doctrine's flush cycle, the auditor inspects the Unit of Work to collect inserts, updates, and deletes. For updates, the full change set (old and new values) is recorded.
3. **Storage** -- Captured changes are wrapped in a `StorageDto` and dispatched to one or more storage backends (Doctrine tables, JSONL files, or a custom service). Storages can be synchronous or asynchronous.
4. **Transaction grouping** -- All changes within a single flush are grouped under one transaction record that includes the username (provided by a `TransactionProviderInterface` implementation) and a timestamp.

## Configuration reference

```yaml
precision_soft_doctrine_audit:
    storages:
        # Doctrine storage -- writes audit rows into a dedicated database
        <name>:
            type: doctrine                     # required
            entity_manager: <em_name>          # required -- the entity manager for the audit database
            connection: <connection_name>       # optional -- defaults to the entity manager's connection
            logger: <logger_service_id>        # optional
            config:
                transaction_table_name: 'audit_transaction'  # optional

        # File storage -- appends JSONL entries to a file
        <name>:
            type: file                         # required
            file: '%kernel.project_dir%/var/audit.log'  # required

        # Custom storage -- delegates to your own StorageInterface implementation
        <name>:
            type: custom                       # required
            service: App\Service\MyStorage     # required -- must implement StorageInterface

    auditors:
        <name>:
            entity_manager: default            # the source entity manager to audit (default: 'default')
            connection: <connection_name>       # optional -- defaults to the entity manager name
            storages:                           # required -- list of storage names from above
                - <storage_name>
            synchronous_storages:              # optional -- subset of storages executed synchronously (defaults to all)
                - <storage_name>
            transaction_provider: App\Service\TransactionProvider  # required -- must implement TransactionProviderInterface
            logger: <logger_service_id>        # optional
            ignored_fields:                    # optional -- field names to globally ignore
                - created
                - modified
```

## Performance notes

- The auditor reads entity metadata on first flush and caches it for subsequent flushes within the same request.
- Each audited flush triggers one INSERT per transaction plus one INSERT per changed entity per storage. For high-throughput systems, consider using asynchronous storages (e.g., a RabbitMQ-backed custom storage) so that only the message publish happens synchronously.
- The `ignored_fields` option (both global and per-entity via `#[Ignore]`) reduces the number of columns tracked and therefore the volume of audit data written.
- File storage appends JSONL lines and does not open a database connection, making it the lightest option for development or low-volume environments.

## Usage

### Sample config and storage

```yaml
precision_soft_doctrine_audit:
    storages:
        doctrine_one:
            type: doctrine
            entity_manager: audit_em_one
            config: # \PrecisionSoft\Doctrine\Audit\Storage\Doctrine\Configuration
                transaction_table_name: 'audit_transaction'
        file:
            type: file
            file: '%kernel.project_dir%/var/audit.log'
        doctrine_two:
            type: doctrine
            entity_manager: audit_em_two
            config: # \PrecisionSoft\Doctrine\Audit\Storage\Doctrine\Configuration
                transaction_table_name: 'audit_transaction'
        rabbit:
            type: custom
            service: Acme\Shared\Service\AuditStorageService
    auditors:
        doctrine:
            entity_manager: source_em_one
            storages:
                - doctrine
            transaction_provider: Acme\Shared\Service\AuditTransactionProviderService
            logger: monolog.logger
            ignored_fields:
                - created
                - modified
        file:
            entity_manager: source_em_two
            storages:
                - file
            transaction_provider: Acme\Shared\Service\AuditTransactionProviderService
        async:
            entity_manager: source_em_three
            storages:
                - doctrine_two
                - rabbit
            synchronous_storages:
                - rabbit # the rabbit storage will publish the storage dto and a consumer will be required to save to the doctrine storage
            transaction_provider: Acme\Shared\Service\AuditTransactionProviderService
```

```yaml
services:
    Acme\Shared\Service\AuditStorageService:
        arguments:
            $storage: '@precision_soft_doctrine_audit.storage.doctrine_two'
```

```php
<?php

declare(strict_types=1);

namespace Acme\Shared\Service;

use PrecisionSoft\Doctrine\Audit\Contract\TransactionProviderInterface;
use PrecisionSoft\Doctrine\Audit\Dto\Storage\TransactionDto;

final class AuditTransactionProviderService implements TransactionProviderInterface
{
    public function getTransaction(): TransactionDto
    {
        $username = '~';

        return new TransactionDto($username);
    }
}
```

```php
<?php

declare(strict_types=1);

namespace Acme\Shared\Service;

use PrecisionSoft\Doctrine\Audit\Contract\StorageInterface;
use PrecisionSoft\Doctrine\Audit\Dto\Storage\StorageDto;
use PrecisionSoft\Doctrine\Audit\Storage\Doctrine\Storage;
use OldSound\RabbitMqBundle\RabbitMq\ProducerInterface;
use PhpAmqpLib\Message\AMQPMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\SerializerInterface;
use Throwable;

class AuditStorageService implements StorageInterface
{
    private SerializerInterface $serializerInterface;
    private Storage $storage;
    private ProducerInterface $producerInterface;
    private LoggerInterface $loggerInterface;
    private ThrowableHandlerService $throwableHandlerService;

    public function __construct(
        SerializerInterface $serializerInterface,
        Storage $storage,
        ProducerInterface $producerInterface,
        LoggerInterface $loggerInterface,
        ThrowableHandlerService $throwableHandlerService
    ) {
        $this->serializerInterface = $serializerInterface;
        $this->storage = $storage;
        $this->producerInterface = $producerInterface;
        $this->loggerInterface = $loggerInterface;
        $this->throwableHandlerService = $throwableHandlerService;
    }

    public function save(StorageDto $storageDto): void
    {
        try {
            $serializedMessage = $this->serializerInterface->serialize($storageDto, JsonEncoder::FORMAT);

            $this->producerInterface->publish($serializedMessage);
        } catch (Throwable $throwable) {
            $context = $this->throwableHandlerService->getContext($throwable);
            $context['dto'] = $serializedMessage ?? 'could not serialize';

            $this->loggerInterface->error($throwable->getMessage(), $context);
        }
    }

    public function consume(AMQPMessage $amqpMessage): void
    {
        /** @var StorageDto $storageDto */
        $storageDto = $this->serializerInterface->deserialize($amqpMessage->getBody(), StorageDto::class, JsonEncoder::FORMAT);

        $this->storage->save($storageDto);
    }
}
```

### Doctrine storage

This library will register two commands for each auditor with a **doctrine type storage**:

* ``precision-soft:doctrine:audit:schema:create:<em-name>`` - will create the audit database schema for auditor **default**.
* ``precision-soft:doctrine:audit:schema:update:<em-name>`` - will update the audit database schema for auditor **default**.

## Upgrading

### v2.x → v3.0

**`getOperation()` returns `Operation` enum instead of `string`**

Before:

```php
$entity->getOperation() === 'delete'
```

After:

```php
use PrecisionSoft\Doctrine\Audit\Dto\Operation;

$entity->getOperation() === Operation::Delete
$entity->getOperation()->value === 'delete'
```

**`OPERATION_*` constants removed from `AbstractEntityDto`**

Replace any references to `AbstractEntityDto::OPERATION_DELETE / OPERATION_INSERT / OPERATION_UPDATE / OPERATIONS`
with `Operation::Delete / Insert / Update` and `Operation::values()`.

**`FileStorage` JSONL format changed**

- Each entity now includes an `operation` field.
- UPDATE fields that have changed are serialized as `{"old": ..., "new": ...}` instead of a plain value.

## Dev

```shell
git clone git@github.com:precision-soft/symfony-doctrine-audit.git
cd symfony-doctrine-audit

./dc build && ./dc up -d
```

## Inspired by

* https://github.com/xiidea/EasyAuditBundle
* https://github.com/DamienHarper/auditor-bundle
* https://github.com/sonata-project/EntityAuditBundle
