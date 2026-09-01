# Symfony Doctrine Audit

[![ci](https://github.com/precision-soft/symfony-doctrine-audit/actions/workflows/ci.yml/badge.svg)](https://github.com/precision-soft/symfony-doctrine-audit/actions/workflows/ci.yml)
[![PHP >= 8.2](https://img.shields.io/badge/php-%3E%3D8.2-8892BF)](https://www.php.net/)
[![PHPStan Level 8](https://img.shields.io/badge/phpstan-level%208-brightgreen)](https://phpstan.org/)
[![Code Style PER-CS2.0](https://img.shields.io/badge/code%20style-PER--CS2.0-blue)](https://www.php-fig.org/per/coding-style/)
[![License MIT](https://img.shields.io/badge/license-MIT-green)](LICENSE)

**You may fork and modify it as you wish.**

Any suggestions are welcomed.

## Requirements

- PHP >= 8.2
- Symfony 7.* or 8.* -- a Symfony 8 set needs PHP >= 8.4, which is what `symfony/config` 8 requires; below that composer resolves 7.x
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

1. **Entity detection** -- Mark entities for auditing with the `#[Auditable]` PHP attribute. Individual fields can be excluded with `#[Ignore]`. Both attributes are **inherited**: they may be declared on a parent entity or on a mapped superclass, and the nearest declaration wins, so `#[Auditable(false)]` on a child opts it out of a parent's `#[Auditable]`.
2. **Change capture** -- During Doctrine's flush cycle, the auditor inspects the Unit of Work to collect inserts, updates, and deletes. For updates, the full change set (old and new values) is recorded.
3. **Storage** -- Captured changes are wrapped in a `StorageDto` and dispatched to one or more storage backends (Doctrine tables, JSONL files, or a custom service). Storages can be synchronous or asynchronous.
4. **Transaction grouping** -- All changes within a single flush are grouped under one transaction record that includes the username (provided by a `TransactionProviderInterface` implementation) and a timestamp.

Owning `ManyToMany` changes are grouped by association and contain `owner_class`, `owner_identifier`, `field`, `target_class`, and deterministically ordered `added` and `removed` identifier lists. Doctrine storage writes that list to the transaction table's `collection_changes` JSON column; JSONL storage writes the same list under `collections`. Only identifiers are read from the mapped objects, so capturing a change does not serialize object graphs or initialize unrelated associations, and the ordering is stable across runs, which makes two audit trails comparable.

## Limitations

Auditing is driven by Doctrine ORM flush events, so a few categories of changes are intentionally **not** captured:

- **Inverse-side association changes** -- Owning `ManyToMany` collections are audited with deterministic owner and target identifiers under the transaction's `collection_changes` payload. Inverse-side and `OneToMany` collections are not recorded independently because Doctrine persists their relationship through the owning side.
- **Bulk DQL / DBAL operations** -- `UPDATE`/`DELETE` issued via DQL or raw DBAL bypass the Unit of Work and therefore dispatch no flush events, so they produce no audit rows. Mutate entities through the ORM (persist/remove + flush) when an audit trail is required.
- **File storage locking** -- `FileStorage` takes an exclusive advisory lock while writing each complete JSONL record and handles partial writes. The lock coordinates writers that use this storage on the same filesystem; network filesystems must provide working `flock()` semantics.

A few further constraints are not limitations of the flush cycle but properties of how the audit schema is laid out:

- **The audit database must not be the source database.** Audit tables carry the *same names* as the entity tables they mirror, so pointing a doctrine storage's `entity_manager` at the audited connection makes `schema:create` replace your application's tables. Always give the audit storage its own database.
- **A collection's identifiers must be scalar.** Owner and target identifiers are recorded as scalars, with backed enums reduced to their value and dates to ATOM. An entity whose composite primary key contains an association therefore cannot take part in an audited collection - the flush is rejected with `identifier field ... is not scalar`. Audit the join entity of the association instead.
- **Many-to-many join tables are not mirrored.** A join table has no transaction id column, so it is not an audit table and is dropped from the generated audit schema; the collection's history lives in the transaction's `collection_changes` payload instead.
- **Every audited entity needs a primary key**, and its identifier fields cannot be listed in `ignored_fields` or marked `#[Ignore]` -- the audit table's primary key is the entity's key plus the transaction id.
- **`transaction_id_column_type` must be an integer type** (`integer`, `bigint`, `smallint`). The transaction table's `id` is an autoincrement column read back through `lastInsertId()`; anything else is rejected when the container is built.

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
                transaction_table_name: 'audit_transaction'      # optional
                transaction_id_column_name: 'audit_transaction_id'  # optional
                transaction_id_column_type: 'integer'            # optional -- integer, bigint or smallint
                operation_column_name: 'audit_operation'         # optional
                collection_changes_column_name: 'collection_changes' # optional

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
- File storage appends JSONL lines and does not open a database connection, making it the lightest option for development or low-volume environments. Each append takes an exclusive `flock()` for the duration of one record, so many concurrent writers serialize on it.
- Owning `ManyToMany` collection changes cost one identifier read per added or removed target, plus one JSON column on the transaction row. Reading identifiers does not initialize the target entities, so the cost scales with the size of the change, not with the size of the collection.
- Reading through `FileAuditReader` scans the file: a filter is applied per record and a cursor skips lines without decoding them, but there is no index. It suits operational lookups and retention, not reporting over a large history - use a doctrine storage and query the audit tables for that.

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

This library registers two commands for **each pair of auditor and doctrine storage**, so an auditor writing to several audit databases gets a create/update pair per database:

* ``precision-soft:doctrine:audit:schema:create:<auditor-name>:<storage-name>`` - creates the audit database schema.
* ``precision-soft:doctrine:audit:schema:update:<auditor-name>:<storage-name>`` - updates the audit database schema.

Running `schema:update` immediately after `schema:create` emits no statements, so the commands are safe to run from a deployment pipeline.

### File storage: reading and retention

Every `file` storage also registers a reader on the same path, so nothing has to be configured twice:

* ``precision_soft_doctrine_audit.storage.<storage-name>.reader`` -- a `FileAuditReader`, which implements both
  [`AuditReaderInterface`](./src/Contract/AuditReaderInterface.php) and
  [`AuditPurgerInterface`](./src/Contract/AuditPurgerInterface.php).

With **exactly one** `file` storage configured, both contracts are aliased onto that reader and autowire:

```php
use PrecisionSoft\Doctrine\Audit\Contract\AuditReaderInterface;
use PrecisionSoft\Doctrine\Audit\Dto\Query\AuditQuery;
use PrecisionSoft\Doctrine\Audit\Dto\Operation;

public function __construct(private readonly AuditReaderInterface $auditReader) {}

$page = $this->auditReader->read(new AuditQuery(
    entityClass: Order::class,
    identity: ['id' => 42],
    operation: Operation::Update,
    limit: 50,
));

foreach ($page->getTransactions() as $transaction) { /* ... */ }

$nextCursor = $page->getNextCursor();
```

A query reaches collection changes as well as entity rows, so the same call finds a flush that only added or removed a related entity:

```php
$page = $this->auditReader->read(new AuditQuery(entityClass: Tag::class, identity: ['id' => 9]));
```

With several file storages the alias would be ambiguous, so none is registered and the per-storage service id is the only way in.

Retention goes through [`PurgeRequest`](./src/Dto/Query/PurgeRequest.php), which is **dry-run by default**:

```php
use PrecisionSoft\Doctrine\Audit\Contract\AuditPurgerInterface;
use PrecisionSoft\Doctrine\Audit\Dto\Query\PurgeRequest;

$result = $auditPurger->purge(new PurgeRequest(new DateTimeImmutable('-1 year'), 500, false));

$result->getMatchedTransactions();
$result->getPurgedTransactions();
$result->hasMore();
```

A purge removes **whole transactions** only, never individual entity rows, and never more than `batchSize` per call.
`hasMore()` reports whether records older than `before` remain **beyond** this batch, so looping until it is false drains the backlog in bounded steps.

The same two operations are available from the console, one pair per auditor and file storage:

* ``precision-soft:doctrine:audit:read:<auditor-name>:<storage-name>`` -- `--entity-class`, `--identity field=value`
  (repeatable), `--from`, `--until`, `--username`, `--operation`, `--limit`, `--cursor`.
* ``precision-soft:doctrine:audit:purge:<auditor-name>:<storage-name>`` -- `--before` (mandatory), `--batch-size`, and `--force` to purge instead of reporting.

Three properties of this reader are worth knowing before you build on it:

- **The contract is satisfied by the JSONL storage only, and its payload is `@experimental`.** `AuditPage::getTransactions()` returns the decoded JSONL records as they are on disk. There is no doctrine-backed reader yet, and the shape becomes a dedicated DTO once there is one -- the method signatures are stable, but what they carry is not covered by the backward compatibility promise until then.
- **A cursor is a line offset**, opaque but not stable: a purge between two pages shifts the lines, so a cursor held across a purge resumes at the wrong place. Page through in one pass, or re-query from the start.
- **A collection change matches on either side of the association.** `entityClass` matches its `owner_class` or its `target_class`, and `identity` matches the owner's identifier or one of the added or removed target identifiers. `operation` is entity-only -- a collection change carries none, so setting `operation` narrows the query to entity rows and excludes collection changes.

Purge rewrites the audit file, so it is a maintenance operation: run it when audited flushes are not in flight. An interrupted purge leaves the records it meant to keep next to the audit file as `<file>.purge` and refuses to run again until that file is dealt with, rather than leaving a truncated audit trail.

## Upgrading

### v3.x → v4.0

**Run the audit schema update command.** The transaction table gains a nullable `collection_changes` JSON column:

```bash
bin/console precision-soft:doctrine:audit:schema:update:<auditor-name>:<storage-name> --force
```

**`Storage::getTransactionId()` takes the whole `StorageDto`.** The collection payload belongs on the transaction row, so subclasses overriding it must widen the parameter:

```php
protected function getTransactionId(StorageDto $storageDto): int
```

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

## Exception context

Every exception in this package carries a structured `context` array next to its message, so the facts describing a failure do not have to be parsed back out of a string:

```php
try {
    // ...
} catch (Exception $exception) {
    $logger->error($exception->getMessage(), $exception->getContext());
}
```

`getContext()` returns `[]` when nothing was attached. `setContext()` replaces it and returns the exception, and the constructor accepts it as an optional fourth argument. Values are expected to be scalars, so the array stays serialisable by a logger.

The context is purely **additive**: no message, code or previous throwable changed when it was introduced, so code that logs only `getMessage()` behaves exactly as before.

What this bundle attaches:

- `DoctrineSchemaListener` reports `entityTableName` (per-table generation) or `transactionTableName` (transaction table generation) when schema generation fails. Both are in the message too, but only as formatted text.
- `StorageFailureException` reports `failedStorages` — the class name of every sink that rejected the payload — and
  `storedPayload`. `getFailures()` returns `Throwable`s, and a throwable cannot name the storage that raised it, so the context is the only place that mapping exists.

Every exception in the package implements `Contract\ExceptionInterface`, so a consumer can read the context off any of them without knowing the concrete class. A subclass of your own that already declares a `$context` property or a
`getContext()`/`setContext()` method will collide with `Exception\Trait\ExceptionTrait`.

## Dev

```shell
git clone git@github.com:precision-soft/symfony-doctrine-audit.git
cd symfony-doctrine-audit

./dc build && ./dc up -d
```

Run the full gate the way the pre-commit hook runs it - the CI workflow in
`.github/workflows/ci.yml` calls the same composer scripts, so the two cannot drift:

```shell
.dev/validate/all.sh
.dev/validate/all.sh --audit    # also audits the locked dependencies ( needs the network )
.dev/validate/all.sh --staged   # what the pre-commit hook runs: nothing unless the index carries php
```

Mutation testing is opt-in for the same reason, plus cost - it runs the suite once per mutant:

```shell
.dev/validate/all.sh --mutation
```

Infection is a pinned phar in the image, not a composer dependency, and `infection.json5` carries a
`minMsi` floor equal to the last measured score, so the section fails when a change makes the suite weaker rather than only reporting a number. Raise the floor when the score improves.

The integration suite needs real databases, which are behind a Compose profile so the default `up`
stays fast and offline:

```shell
./dc --profile db up -d
.dev/validate/all.sh --integration
```

Tests connect through `DATABASE_URL_MYSQL` and `DATABASE_URL_MARIADB` and skip themselves when those services are not running, so `composer check` never depends on them.

Build against another PHP version with the `PHP_VERSION` build argument - each version is tagged as its own image, so switching back and forth costs nothing:

```shell
PHP_VERSION=8.4 ./dc build && PHP_VERSION=8.4 ./dc up -d
```

Coverage is available through pcov, which is installed but disabled by default:

```shell
./dc exec dev php -d pcov.enabled=1 vendor/bin/simple-phpunit --coverage-text
```

After editing a file, `./dc restart dev` (a few seconds) is enough to be sure the container is not serving a stale copy - the bind mount can keep the old inode after an atomic rewrite.

## Inspired by

* https://github.com/xiidea/EasyAuditBundle
* https://github.com/DamienHarper/auditor-bundle
* https://github.com/sonata-project/EntityAuditBundle
