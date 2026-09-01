# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [v4.0.0] - 2026-09-01 - Many-to-many collection auditing and jsonl read and retention contracts

### Upgrading from v3

- **Run the audit schema update command.** The transaction table gains a nullable `collection_changes` JSON column, and an audited flush that changes an owning `ManyToMany` collection writes to it.
- **`Storage::getTransactionId()` now takes the whole `StorageDto`** instead of just its `TransactionDto`, because the collection payload belongs on the transaction row. Subclasses that override it must widen the parameter.
- Consumers pinning `precision-soft/symfony-doctrine-audit: 3.*` move to `4.*`.

### Added

- Owning `ManyToMany` collection additions and removals are captured with deterministic owner and target identifiers. Doctrine storage keeps the payload in the transaction table's configurable `collection_changes` JSON column, while JSONL exposes the same data under `collections`.
- `AuditReaderInterface` and `AuditPurgerInterface`, with `FileAuditReader` implementing both over JSONL. Both are marked `@experimental`: the method signatures are stable, but `AuditPage` hands back the jsonl records verbatim and that payload becomes a dedicated transaction dto once a second storage implements the contract. Queries support entity class and identity, transaction range, username, operation, a bounded limit and an opaque cursor; purge is dry-run by default, removes whole transactions only, and is bounded by a batch size.
- Every `file` storage now registers its reader as `precision_soft_doctrine_audit.storage.<name>.reader`, reusing the path already validated for the storage. With exactly one file storage the two contracts are aliased onto it, so they autowire.
- `precision-soft:doctrine:audit:read:<auditor>:<storage>` and `precision-soft:doctrine:audit:purge:<auditor>:<storage>` for every `file` storage. Purge reports what it would remove unless `--force` is passed.
- Queries reach collection changes, not only entity rows: `entityClass` matches an association's `owner_class` or `target_class`, and `identity` matches the owner's identifier or one of the added or removed target identifiers. `operation` stays entity-only, since a collection change carries none.
- `collection_changes_column_name` for the doctrine storage config.

### Fixed

- `FileStorage` now holds an exclusive file lock for each complete JSONL record and handles partial writes, preventing concurrent writers from interleaving large audit entries.
- Audit schema generation no longer fails when an audited entity owns a `ManyToMany`. A table without the transaction id column is not an audit table, so it is dropped from the audit schema instead of being handed a foreign key it cannot carry - previously `postGenerateSchema()` threw `ColumnDoesNotExist` on the join table and took the whole schema down.
- `FileAuditReader::purge()` reports `hasMore` for records beyond the current batch only. A dry run used to count the records it had just matched as leftovers, so a `do { … } while ($result->hasMore())` loop never terminated.
- `FileAuditReader::purge()` streams the records it keeps instead of buffering the whole file, and copies them back into the original inode - so memory no longer scales with the audit file, the `flock()` contract with `FileStorage` still holds, and an interrupted purge leaves the complete set of kept records on disk as `<file>.purge` rather than a truncated audit file.

### Changed

- Symfony component constraints now accept 7.x as well as 8.x. A Symfony 8 set resolves from PHP 8.4 up, since that is `symfony/config` 8's own floor; below it composer keeps 7.x. The new `symfony-latest` CI lane resolves the top of the allowed range on PHP 8.5 and runs phpstan and the suite against it, while `composer.lock` stays on the reproducible baseline set. CI also covers PHP 8.5 against the locked toolchain.
- Reading a page no longer decodes the records it skips, so paging deeper into a jsonl file stays cheap.
- The locked, partial-write-safe file io shared by `FileStorage` and `FileAuditReader` moved into `Storage\Trait\FileLockTrait`, whose `openFile`/`lockFile`/`writeFile`/`flushFile` seams are overridable.
- `StorageDto::getCollectionChangesAsArray()` returns the persisted shape of the collection payload, so the doctrine and jsonl storages serialize it through one place instead of two copies of the same mapping.

## [v3.5.0] - 2026-08-17 - The audit schema can be generated again, and #[Auditable] is inherited

### Fixed

- `DoctrineSchemaListener::configureAuditTable()` — **the audit schema could not be generated at all.** The guard that verifies no identifier column was dropped compared the primary-key column names against `\array_keys($table->getColumns())`; DBAL 4 returns `Table::getColumns()` through `array_values()`, so that expression yields `0..n` instead of the column names and every audited entity was rejected with ``primary key column `id` was dropped``. Replaced with `Table::hasColumn()`, which also normalizes the identifier. No unit test had ever exercised the auditable-entity path — every existing case covered a rejection branch — so the DBAL 3 → 4 regression went unnoticed
- `DoctrineSchemaListener::configureAuditTable()` — **`schema:update` never settled.** The operation column was declared with the custom `AuditOperationType`, but DBAL 4 introspects a MySQL `enum` column back into its own `Doctrine\DBAL\Types\EnumType`, never into a subclass, so the comparison always found a difference and every run re-issued `ALTER TABLE ... CHANGE audit_operation ENUM(...)` for every audit table. The column is now declared with DBAL's `Types::ENUM` plus an explicit `values` option — the emitted DDL is byte-for-byte the same, and `schema:update` immediately after `schema:create` now emits nothing. `AuditOperationType` remains registered and public for consumers mapping the audit table in their own entities
- `AnnotationReadService::buildEntityDto()` — **`#[Ignore]` on a private property of a mapped superclass was silently ignored and the field was audited.** Doctrine merges a mapped superclass's fields into every child's metadata, but `ReflectionClass::getProperties()` on the child does not return a parent's private properties, so the attribute was never seen. The class hierarchy is now walked, with the most-derived declaration of a property winning
- `Auditor::save()` / `Auditor::postFlush()` — a partial storage failure was reported as a total loss. With several storages where only some failed, the surviving storage had persisted the payload, yet the dead-letter `critical` fired with the message ``every configured audit storage failed`` and the full payload attached, so replaying it duplicated the row that had already been written. `save()` now throws the new `Exception\StorageFailureException`, which carries every failure and whether any storage accepted the payload; the dead letter is emitted only when none did. The per-storage `error` log now also names the failing storage class
- `Configuration::attachAuditors()` — omitting the required `storages` option produced an `Undefined array key` warning and an `array_diff(null, ...)` `TypeError` from `beforeNormalization`, which runs before `isRequired()` can reject anything. The callback now returns untouched, so the config tree reports the missing option properly
- `PrecisionSoftDoctrineAuditExtension::defineSchemaCommands()` — an auditor with more than one doctrine storage (as in this README's own sample config) had the second storage's schema commands overwrite the first's, leaving one audit database with no way to create its schema. Both the service id and the console command name now include the storage name: `precision-soft:doctrine:audit:schema:create:<auditor>:<storage>`
- `Auditor::getOriginalEntityData()` — the version field was read through `ClassMetadata::$reflFields`, which is deprecated since ORM 3.3 and removed in ORM 4, so auditing a versioned entity emitted a Doctrine deprecation on every flush. Now reads through `ClassMetadata::getFieldValue()`

### Changed

- **`#[Auditable]` is now inherited.** `ReflectionClass::getAttributes()` does not return a parent's attributes, so a declaration on a base class was invisible — which made both Doctrine inheritance strategies unusable in their natural form. Under single-table inheritance the flushed object is always a concrete child while the only table belongs to the root, so annotating the root produced an audit table and no rows, and annotating the children produced rows and no table; only annotating every class in the hierarchy worked, and nothing said so. The hierarchy is now walked with the nearest declaration winning, which also makes `#[Auditable(false)]` on a child a working opt-out from an inherited enable. **After upgrading, re-run the schema commands before the next flush**: a child of an annotated parent that was previously unaudited now becomes audited and needs its audit table
- `Storage\Doctrine\Configuration` — `transaction_id_column_type` is validated against `integer`, `bigint` and `smallint` and throws otherwise. The transaction table's `id` is an autoincrement column read back through `lastInsertId()`, so only an integer identity type can work; `guid` used to create a `char(36)` with the autoincrement silently dropped, so `CREATE TABLE` succeeded and every audited flush failed afterwards
- `phpstan-baseline.neon` — **deleted.** All 40 entries and 209 suppressed occurrences are gone: the mock properties in the test suite were typed as the union `X|MockInterface`, on which neither `shouldReceive()` nor the constructor parameter type holds, and the intersection `X&MockInterface` is both true and accepted by PHP 8.1+. The two remaining errors are false positives from incorrect vendor annotations and now live in `phpstan.neon` as `ignoreErrors` entries that state their reason
- `phpstan.neon` — the `return.type` suppression on `AuditOperationType` is **removed**. It existed only because `AbstractPhpEnumType::getValues()` in `precision-soft/doctrine-type` declared `array<int, UnitEnum>` while supporting a `notEnum` mode that passes scalars through — which is the mode this type uses. doctrine-type v3.5.0 widened that declaration, so the pattern stopped matching and PHPStan reported it as a non-ignorable `ignore.unmatched` error. The suppression had done its job and outlived its cause
- `README.md` — documented attribute inheritance, the storage config options, the per-storage command names, and four constraints that were previously implicit: the audit database must not be the source database, audited entities need a primary key that cannot be ignored, `transaction_id_column_type` must be an integer type, and concurrent large appends to `FileStorage` are not atomic
- `Trait\ThrowTrait::throw()` — the exception context now survives the rewrap. Every failure leaving the auditor passes through this trait, which built a fresh `Exception` from the message, code and previous throwable and **dropped the context**, so a consumer catching what `postFlush()` throws saw `getContext() === []` while the facts sat one link down the `previous` chain. `StorageFailureException`'s `failedStorages` is the case that matters: a `Throwable` cannot name the sink that raised it, so if that array does not survive the rewrap it is unreachable for the caller. Purely additive — message, code and previous are unchanged, and a throwable that carries no context still produces an empty one
- `Auditor::postFlush()` — the `gc_collect_cycles()` comment no longer claims a memory benefit it does not demonstrably provide. Measured over 1000 audited inserts against real MySQL without clearing the entity manager, peak memory is identical with and without it and the time difference sits inside the database's own run-to-run variance. The call is kept; only the claim was narrowed
- comments across the package normalized to the house rule — the default is no comment, and a warranted one is a single short line. Every multi-line rationale block, narrative test docblock and shell section header was removed; the `.dev/` scripts, the `Dockerfile` and the compose file now carry nothing but their shebang and one line about `tini` as PID 1. Nothing behavioral changed. `CONTRIBUTING.md` gained the two sections that now carry the rationale — *Development toolchain* (the pinned pcov and infection builds, the `php.dev.ini` overlay, the `db` profile, the mutation thresholds) and *Continuous integration* (the jobs, and why `--fail-on-skipped` is passed in CI only) — and its *Verification* section now documents `.dev/validate/all.sh` and its flags, replacing the stale description of the old hook

### Added

- `Contract\ExceptionInterface` and `Exception\Trait\ExceptionTrait` — exceptions now carry a structured `context` array alongside the message, read with `getContext()` and set with `setContext()` or the new fourth constructor argument. The context is purely additive: no existing message, code or previous throwable changed, so a consumer logging only `getMessage()` sees exactly what it saw before. Ported from `precision-soft/symfony-console`, which has carried it since v4.5.0, so every package in the portfolio now exposes the same contract. Note for consumers subclassing the package exception: a subclass that already declares its own `$context` property or a `getContext()`/`setContext()` method will collide with the trait
- `DoctrineSchemaListener` — both schema-generation failure paths now report the table they were building in the exception context (`entityTableName` / `transactionTableName`), so a consumer no longer has to parse it back out of the formatted message
- `StorageFailureException` — takes an optional `context` argument and `Auditor::save()` fills it with `failedStorages` (the class name of every sink that rejected the payload) and `storedPayload`. `getFailures()` returns `Throwable`s, which cannot name the storage that raised them, so this was the one fact the exception could not previously carry
- `Exception\StorageFailureException` — carries every storage failure and whether the payload reached at least one storage
- `tests/Functional/` — the bundle's first integration suite, run against **real MySQL 8.4 and MariaDB 11.4**: the audit schema is created for audited entities only and with the expected columns, primary key and foreign key; `schema:update` after `schema:create` emits nothing; inserts, updates and deletes produce the right rows through a real `flush()`; both inheritance strategies, the `#[Auditable(false)]` opt-out and ignored fields behave as documented; a failing audit sink leaves the entity committed and dead-letters only when nothing survived; and the doctrine and file storages describe the same operations. `SchemaTool` is constructed inside `AbstractCommand`, so this is the first coverage its `execute` path has ever had
- `composer.json` — a `test-integration` script; `test` now excludes the `integration` group so `composer check` stays fast and offline
- `DoctrineSchemaListenerTest::testPostGenerateSchemaTableBuildsTheAuditTableForAnAuditableEntity` — the happy path, on real DBAL objects and therefore in the offline gate
- `AnnotationReadServiceTest` — coverage for the Doctrine proxy-marker branch of `resolveEntityClass()`, for inherited `#[Auditable]` and its opt-out, and for `#[Ignore]` on a private mapped-superclass property
- `AuditorTest` — coverage for partial versus total storage failure, and a test pinning that auditing a versioned entity triggers no Doctrine deprecation
- coverage for three column contracts the schema tests could not see. **The generated audit table is now asserted to emit as `CREATE TABLE` SQL** — the whole job of `configureAuditTable()` is to produce a table the platform can create, and the tests only ever inspected it column by column, so a shape DBAL refuses to emit (a `Types::ENUM` missing its `values`, for instance) passed every assertion and failed at `doctrine:schema:create` instead. **The transaction table's column options are asserted exactly** rather than through a matcher that accepted any array, which is what left the `autoincrement` flag unpinned — and `Storage::getTransactionId()` reads that column back through `lastInsertId()`, so without it `CREATE TABLE` succeeds and every audited flush fails afterwards. **And the audit operation column is asserted `NOT NULL`**, since every audit row records an operation

## [v3.4.4] - 2026-06-17 - Replace production asserts with explicit checks and document limitations

### Fixed

- `Auditor::createAuditorEntityDtos()` — the inheritance discriminator-column null check no longer relies on `\assert()`, which is a no-op under `zend.assertions=-1` in production; a missing discriminator column on an inheritance-mapped entity now throws a clear `Exception` instead of failing later with an opaque error
- `DoctrineSchemaListener::configureAuditTable()` — the entity primary-key null check no longer relies on `\assert()` either; an audited entity whose table has no primary key now throws a clear `Exception` instead of failing later with an opaque "method call on null" fatal in production

### Changed

- `README.md` — added a **Limitations** section documenting that to-many / inverse-side collection changes and bulk DQL/DBAL operations are not audited (both inherent to flush-event-based auditing)

### Added

- `composer.json` — added `test`, `phpstan`, `cs-check`, `cs-fix` and an aggregate `check` convenience script wrapping `simple-phpunit`, `phpstan`, and `php-cs-fixer`
- `DoctrineSchemaListenerTest::testPostGenerateSchemaTableThrowsWhenEntityTableHasNoPrimaryKey` covering the primary-key guard

## [v3.4.3] - 2026-04-23 - Complete extensibility pass: constants, properties, and fluent return types

### Changed

- `PrecisionSoftDoctrineAuditExtension::BASE_COMMAND_NAME` and `BASE_SERVICE_ID` — visibility widened from `private const` to `protected const`; all usages switched from `self::` to `static::` so subclasses can override the command name prefix and service ID prefix and have all `get*Id()` / `get*ConfigId()` helpers honor the override
- `Storage\Doctrine\Configuration::DEFAULT_TRANSACTION_TABLE`, `DEFAULT_TRANSACTION_ID_COLUMN`, `DEFAULT_TRANSACTION_ID_TYPE`, `DEFAULT_OPERATION_COLUMN` — visibility widened from `private const` to `protected const` and all 4 constructor usages switched from `self::` to `static::` so subclasses can override default column and table names without duplicating the constructor body
- `Storage\Doctrine\Configuration::$transactionTableName`, `$transactionIdColumnName`, `$transactionIdColumnType`, `$operationColumnName` — visibility widened from `private readonly` to `protected readonly`; complements the `protected const DEFAULT_*` widening above
- `Auditor::$configuration`, `$entityManager`, `$storages`, `$transactionProvider`, `$logger`, `$annotationReadService` — visibility widened from `private readonly` to `protected readonly`
- `Auditor::$auditedEntities`, `$auditorDto` — visibility widened from `private` to `protected`
- `Auditor\Configuration::$ignoredFields` — visibility widened from `private readonly` to `protected readonly`
- `DoctrineSchemaListener::$annotationReadService`, `$auditorConfiguration`, `$storageConfiguration` — visibility widened from `private readonly` to `protected readonly`; the listener already exposes `protected configureAuditTable()` and `updateType()` confirming extension is expected
- `FieldDto::$name`, `$columnName`, `$type`, `$value`, `$oldValue`, `$hasOldValue` — visibility widened from `private readonly` to `protected readonly`
- `AuditorDto::$auditEntities` — visibility widened from `private` to `protected`
- `AuditorDto::$entitiesToDelete`, `$entitiesToInsert`, `$entitiesToUpdate`, `$entityChangeSets` — visibility widened from `private readonly` to `protected readonly`
- `AuditorDto::addAuditEntity()` — return type widened from `self` to `static`
- `Annotation\EntityDto::$class`, `$ignoredFields` — visibility widened from `private readonly` to `protected readonly`
- `Storage\EntityDto`-based DTOs (`StorageDto::$transaction`, `$entities`; `TransactionDto::$username`, `$extras`) — visibility widened from `private readonly` to `protected readonly`
- `Storage\Doctrine\Storage::$entityManager`, `$configuration`, `$logger` — visibility widened from `private readonly` to `protected readonly`
- `Storage\FileStorage::$filesystem`, `$jsonEncoder`, `$file`, `$logger` — visibility widened from `private readonly` to `protected readonly`; the class already exposes `protected buildTransaction()` and `getLogger()` for extension
- `EntityDto::addField()` — return type widened from `self` to `static`; `EntityDto` extends `AbstractEntityDto` which declares `protected array $fields`, confirming subclassing is expected
- `AnnotationReadService::$entityDtoCache` — visibility widened from `private` to `protected`; the class exposes three `protected` helper methods (`hasAuditableAttribute`, `hasEntityAttribute`, `hasIgnoreAttribute`) confirming extension is expected, and subclasses that override `buildEntityDto()` need cache access

## [v3.4.2] - 2026-04-23 - Extend Late Static Binding to Configuration and AbstractCommand

### Fixed

- `Configuration::attachStorages()` — changed `self::TYPE_DOCTRINE`, `self::TYPE_FILE`, `self::TYPE_CUSTOM` to `static::` — class has `protected` methods and is subclassable, so `self::` would bypass overridden constants in subclasses
- `AbstractCommand::execute()` — changed `self::FAILURE` and `self::SUCCESS` to `static::` — abstract class with public inherited constants from Symfony `Command`; the v3.4.1 pass fixed `self::FORCE` but missed `FAILURE` and `SUCCESS`
- `CHANGELOG.md` — backfilled v2.1.1 entry: `composer.json` version field was corrected from `v2.1.0` to `v2.1.1`

## [v3.4.1] - 2026-04-23 - Fix Exception Policy, Static Binding, and Inline FQN

### Fixed

- `Auditor::createAuditEntities()` and `Auditor::createStorageDto()` — replaced raw `LogicException` guards with the project-specific `Exception`; `LogicException` was imported from the global namespace, bypassing the library's own catchable exception type
- `AnnotationReadService::getEntityClass()` — changed `self::resolveEntityClass()` to `static::resolveEntityClass()` so subclasses can override the static method; `self::` always resolves to the declaring class and prevents runtime polymorphism in non-final classes
- `AbstractCommand::execute()` — changed `self::FORCE` to `static::FORCE` for consistency with `configure()` (line 49 already used `static::`) and to respect subclass constant overrides
- `AnnotationReadServiceInterface` — replaced inline `\ReflectionException` FQN in `@throws` with a `use ReflectionException;` import per project convention (all class references must go through `use` at the top of the file)

### Changed

- `Auditor::getScalarChangeSetEntry()` — removed two-line prose PHPDoc description (redundant with method name and type signatures); `@param`/`@return` annotations retained for static analysis
- `Storage::saveEntity()` — typed closure parameter `fn($columnName)` → `fn(string $columnName)`
- `AbstractCommand::getAuditedSourceMetadatas()` — typed closure parameter `fn($classMetadata)` → `fn(ClassMetadata $classMetadata)`
- `AuditorTest`, `StorageTest`, `DoctrineSchemaListenerTest`, `ExceptionTest`, `ThrowTraitTest` — replaced `RuntimeException` / `InvalidArgumentException` stubs with the project-specific `Exception`; `\Throwable` inline FQN in `ThrowTraitTest` replaced with a `use Throwable` import; `phpstan-baseline.neon` updated for the shifted anonymous-class line reference

## [v3.4.0] - 2026-04-20 - Rollback Safety, Dead-Letter Logging, and UPDATE New-Value Correctness

### Fixed

- `Auditor::postFlush()` — added a dead-letter branch: when every configured audit storage throws, the full `StorageDto` payload is emitted to the logger at `critical` (`audit_dead_letter` message with `exception` + `storage_dto` keys) before the exception is re-thrown, so the audit row is recoverable instead of silently lost. The outer Doctrine transaction has already committed by the time `postFlush` runs, which is what makes the silent-loss window possible (SDA-101)
- `Auditor::onFlush()` — clears `$this->auditorDto` both at entry and in the catch path so a rolled-back previous flush can no longer seed a phantom audit row on the next successful flush. Doctrine does not dispatch `postFlush` on rollback, so the previous flush's `finally`-reset never ran; the entry clear replaces it (SDA-102)
- `Auditor::createAuditorEntityDtos()` — for `UPDATE` operations, the new scalar and to-one-association values are now read from `$changeSet[$field][1]` instead of `$entityData[$field]`. `UnitOfWork::getOriginalEntityData()` populates `$entityData` with the *pre-update* snapshot, so the old code was silently storing the old value as the new value on updates (SDA-103)
- `PrecisionSoftDoctrineAuditExtension::defineStorageDoctrine()` — validates `entity_manager` before calling `getEntityManagerAndConnection()`; previously the PHP-level "undefined index" was surfaced as a fatal instead of the descriptive `the `entity_manager` config is mandatory for storage type `<type>`` exception
- `PrecisionSoftDoctrineAuditExtension::getEntityManagerAndConnection()` — `$config['entity_manager']` access guarded with `?? ''` so callers that pre-validate (or accept the empty default) no longer trip undefined-index notices

### Changed

- `Auditor::createAuditEntities()` / `createStorageDto()` — replaced the `\assert(null !== $this->auditorDto)` narrowing with explicit `throw new LogicException(...)` statements; `\assert()` is a no-op under `zend.assertions=-1` in production, so the assert-only form could surface a later `->entitiesToInsert` access as a less helpful null-dereference error
- `Auditor::createAuditorEntityDtos()` — `$changeSet` PHPDoc widened from `array<string, array{0: mixed, 1: mixed}|null>` to `array<string, array{0: mixed, 1: mixed}|PersistentCollection<int, object>>|null` so static analysis sees the real shape (Doctrine reports to-many associations as `PersistentCollection` entries inside the change-set)
- `AuditorDto::$entityChangeSets` + `getEntityChangeSet()` — PHPDoc widened to the same `array{0: mixed, 1: mixed}|PersistentCollection<int, object>` shape, matching what `UnitOfWork::getEntityChangeSet()` actually produces
- `AnnotationReadService::buildEntityDto()` — dropped `?? new ReflectionClass($className)` fallback; `ClassMetadata::getReflectionClass()` is non-nullable in the Doctrine ORM versions this library supports
- `phpstan-baseline.neon` — regenerated after the type-safety improvements above

### Added

- `Auditor::getScalarChangeSetEntry()` — new protected helper that narrows a change-set entry to the scalar `array{0: mixed, 1: mixed}` tuple used for to-one associations and scalar fields; returns `null` for absent fields and for `PersistentCollection` entries (to-many / inverse-side are not tracked by this auditor — see SDA-106 for full collection-change support)
- `tests/Auditor/AuditorTest.php` — three new regression tests totalling 296 lines:
    - `testPostFlushEmitsDeadLetterWhenAuditStorageWriteFails` — SDA-101 guard
    - `testRolledBackFlushDoesNotEmitPhantomAuditOnNextFlush` — SDA-102 guard
    - `testUpdateFieldDtoCarriesNewValueNotOldValue` — SDA-103 guard

## [v3.3.1] - 2026-04-19 - PHPDoc Typing, Static-Analysis Invariants, and Named Configuration Constants

### Changed

- `Auditor::$auditedEntities` — type hint corrected to `array<string, AnnotationEntityDto>|null`
- Library-wide PHPDoc typing: `@phpstan-param ClassMetadata<object>` / `ReflectionClass<object>` generics, `@throws` clauses, and `@param`/`@return` shapes added across `Auditor`, `AnnotationReadService`, `AnnotationReadServiceInterface`, `PrecisionSoftDoctrineAuditExtension`, DTOs, schema commands, and `ThrowTrait`
- `Auditor::createAuditorEntityDtos()` — discriminator column access refactored to a local variable with `assert(null !== ...)` (static-analysis friendly, no runtime change)
- `Auditor::createAuditEntities()` / `createStorageDto()` — added `assert(null !== $this->auditorDto)` invariants
- `PrecisionSoftDoctrineAuditExtension` — dropped unreachable `null === $entityManager` branch
- `FileStorage::save()` — idiomatic `[] === $storageDto->getEntities()` empty check
- `phpstan-baseline.neon` — regenerated after type-safety improvements

## [v3.3.0] - 2026-04-13 - Multi-storage Error Handling, Rollback Safety, and Visibility Widening

### Fixed

- `Auditor::save()` — log each storage failure individually instead of swallowing subsequent exceptions; all storages are still attempted, first exception is re-thrown
- `Storage::save()` — wrap `rollBack()` in try/catch to preserve the original exception when the rollback itself fails
- `Storage::saveEntity()` — explicit `false` check on `lastInsertId()` before casting to `int`; throws `Exception` when the driver returns `false`

### Changed

- `Auditor::$auditedEntities` PHPDoc corrected from `@var EntityDto[]` to `@var AnnotationEntityDto[]`
- `Auditor` — all 9 `private` methods widened to `protected` (`save`, `createAuditEntities`, `createAuditorEntityDtos`, `getTableName`, `getColumnName`, `getOriginalEntityData`, `createStorageDto`, `filterAuditedEntities`, `hasAuditedEntity`)
- `PrecisionSoftDoctrineAuditExtension` — all `private` methods widened to `protected`
- `Configuration` — `attachStorages()`, `attachAuditors()` visibility widened from `private` to `protected`
- `Storage` — `getLogger()`, `getTransactionId()`, `saveEntity()` visibility widened from `private` to `protected`
- `FileStorage` — `getLogger()`, `buildTransaction()` visibility widened from `private` to `protected`
- `AnnotationReadService` — `hasAuditableAttribute()`, `hasEntityAttribute()`, `hasIgnoreAttribute()` visibility widened from `private` to `protected`
- `ThrowTrait` — abstract `getLogger()` and `throw()` visibility widened from `private` to `protected`
- `DoctrineSchemaListener` — `configureAuditTable()`, `updateType()` visibility widened from `private` to `protected`

## [v3.2.3] - 2026-04-11 - Remove Final From DTOs, Harden Storage Save, and Migrate Tests to AbstractTestCase

### Fixed

- `FileStorage::save()` — skip writing when the entities list is empty
- `Auditor::save()` — attempt all storage backends even if one fails; first exception is re-thrown after all storages have been tried

### Changed

- `Storage\Doctrine\Configuration` — properties marked `readonly`; `@param` PHPDoc added
- Removed `final` from 9 DTO/Attribute classes: `FieldDto`, `AuditorDto`, `Auditor\EntityDto`, `Annotation\EntityDto`, `Storage\EntityDto`, `StorageDto`, `TransactionDto`, `Auditable`, `Ignore`
- Migrated 4 Mockery-based test classes to `AbstractTestCase`: `AuditorTest`, `AnnotationReadServiceTest`, `StorageTest`, `DoctrineSchemaListenerTest`

## [v3.2.2] - 2026-04-10 - Add Extras Schema Column, Persist Extras, and Track Association Old Values

### Fixed

- `Auditor` — track old association field values in the audit trail (associations previously always recorded `null` as old value on update)
- `Storage::getTransactionId()` — persist `extras` data in Doctrine storage (was silently ignored)
- `Exception` — removed `final` modifier to allow extension

### Changed

- `DoctrineSchemaListener::postGenerateSchemaTable()` — extracted audit table configuration into private `configureAuditTable()` method
- `AnnotationReadService` — `empty()` checks replaced with explicit `[] ===` comparisons
- `Storage::save()` / `FileStorage::buildTransaction()` — `empty()` checks replaced with explicit `[] ===` / `[] !==` comparisons
- `phpstan-baseline.neon` updated

### Added

- `DoctrineSchemaListener::postGenerateSchema()` — adds the `extras` column to the `audit_transaction` table schema (column was missing from schema generation)

## [v3.2.1] - 2026-04-09 - Harden Schema Listener and Audit Storage Validation

### Fixed

- `Auditor::onFlush()` — `empty()` checks replaced with explicit `[] ===` comparisons on audited entity lists
- `Auditor::createStorageDto()` — throws `Exception` instead of silently skipping when all fields of an entity are ignored
- `Configuration` — `empty()` check replaced with explicit `[] !==` comparison for `synchronous_storages` validation
- `DoctrineSchemaListener` — `isset($associationMapping->joinColumns)` guard replaced with `instanceof ManyToOneAssociationMapping / OneToOneOwningSideMapping` check for correct owning-side detection
- `DoctrineSchemaListener` — throws `Exception` when a primary key column has been dropped via the ignored fields list

## [v3.2.0] - 2026-04-07 - Introduce AnnotationReadServiceInterface, Remove Final Modifiers, and Align Doctrine 3 Mappings

### Breaking Changes

- `AnnotationReadService::getEntityClass()` — static method renamed to `resolveEntityClass()`; callers using the static form must update, or inject `AnnotationReadServiceInterface` and call the instance method
- `Auditor`, `PrecisionSoftDoctrineAuditExtension`, `DoctrineSchemaListener` — removed `final` modifier to allow extension

### Fixed

- `DoctrineSchemaListener` — `instanceof` and `\in_array()` checks wrapped in explicit `true ===`
- `AnnotationReadService` — attribute reading simplified: `foreach` loop replaced with direct `$attributes[0]` access after `empty()` guard
- `DoctrineSchemaListener::postGenerateSchemaTable()` — resolves join column field names by iterating `associationMappings` when direct field lookup returns `null`

### Changed

- `Auditor` — depends on `AnnotationReadServiceInterface` instead of the concrete `AnnotationReadService`; `$entity::class` replaced with `getEntityClass()` for correct proxy-class resolution; `fieldMapping['type']` → `fieldMapping->type` (Doctrine 3 object-style mapping); `createAuditEntities()` refactored from `array_map`/`array_filter` to explicit `foreach`
- `DoctrineSchemaListener` — depends on `AnnotationReadServiceInterface`; uses `$mapping->columnName` directly (Doctrine 3)
- `AbstractCommand` — depends on `AnnotationReadServiceInterface`; renamed `$sql` → `$sqlStatement` in loop
- `PrecisionSoftDoctrineAuditExtension` — `switch` replaced with `match` for storage type resolution; `empty()` checks replaced with explicit `null === || '' ===`; `$storageServiceId` renamed to `$auditorConfigServiceId`
- `ThrowTrait` — `$throwable->getCode()` cast to `(int)` in both constructor calls

### Added

- `AnnotationReadServiceInterface` — new contract with `read()`, `buildEntityDto()`, and `getEntityClass()` methods; `AnnotationReadService` implements it
- `AnnotationReadService::getEntityClass()` — new instance method implementing `AnnotationReadServiceInterface`

## [v3.1.1] - 2026-04-06 - Preserve Nullable Old Values in Audit Trail and Deduplicate Schema Listener Switches

### Fixed

- `Auditor` — `isset($changeSet[$field])` replaced with `\array_key_exists()` to correctly track nullable field changes from `null` (`isset()` returns `false` for `null` values, causing audit trail data loss)
- `Auditor::postFlush()` — `gc_collect_cycles()` no longer runs on the early-return path when no audit work was done
- `AbstractCommand::createSchemaTool()` — accepts `$sourceMetadatas` as parameter to avoid a duplicate `getAuditedSourceMetadatas()` call
- `Configuration` — removed `isRequired()` from `synchronous_storages` node (defaults via `beforeNormalization`)

### Changed

- `Storage::save()` — wrapped in a DBAL transaction with `beginTransaction()`/`commit()`/`rollBack()`
- `Storage::saveTransaction()` — `lastInsertId()` cast to `int` with `0 >= $lastId` check
- `DoctrineSchemaListener` — fixed `$entityTable` to `$table` variable reference, Yoda comparison style, `$t` renamed to `$throwable`
- `ThrowTrait` — `$t` renamed to `$throwable`; error code cast to `(int)` in exception constructor
- `AnnotationReadService::getEntityClass()` — hardcoded `__CG__` proxy string replaced with `Doctrine\Persistence\Proxy::MARKER`
- `FileStorage` — `Filesystem` and `JsonEncoder` are now class properties instead of per-call instances
- `AuditOperationType` class marked `final`
- `PrecisionSoftDoctrineAuditBundle` class marked `final`
- `Exception` class marked `final`
- `PrecisionSoftDoctrineAuditExtension` — `static::` replaced with `self::` for constants in `final` class
- `DoctrineSchemaListener::updateType()` — duplicate switch cases replaced with a single `if` condition for `AbstractEnumType`/`AbstractSetType`
- `.dev/docker/entrypoint.sh` — skip `composer install` when `composer.lock` hash matches cached vendor
- Variable naming compliance: `$sqls` → `$sqlStatements`, `$diff` → `$missingStorages`, `$data` → `$originalEntityData`/`$associationData`, `$type` → `$storageType`/`$fieldType`, `$value` → `$relatedFieldValue`/`$fieldValue`, `$obj` → `$throwTraitUser`, `$mock` → `$annotationReadService`, `$result`/`$first`/`$second` → descriptive names in tests
- `phpstan-baseline.neon` updated

## [v3.1.0] - 2026-04-04 - Upgrade to doctrine-type 3.0, symfony-console 4.0, and PHPUnit 11.5

### Breaking Changes

- Requires `precision-soft/doctrine-type` `^3.0` (was `2.*`)
- Requires `precision-soft/symfony-console` `^4.0` (was `3.*`)

### Fixed

- `UpdateCommand` — removed extra second parameter from `getUpdateSchemaSql()` and `updateSchema()` (Doctrine ORM 3 accepts only 1 parameter)
- `Storage::saveEntity()` — table and column identifiers quoted via `DatabasePlatform::quoteIdentifier()`
- `FileStorage` — `FieldDto::hasOldValue()` used instead of null-checking `getOldValue()` to correctly detect fields with `null` old values
- `DoctrineSchemaListener::postGenerateSchemaTable()` — replaced removed `getFieldForColumn()` with manual `fieldMappings` iteration (DBAL 4 compatibility)

### Changed

- Upgraded from PHPUnit 9 to PHPUnit 11.5 via `precision-soft/symfony-phpunit: ^3.0`
- Replaced `<coverage>` with `<source>`, `<listeners>` with `<extensions>` in `phpunit.xml.dist`
- Added `failOnRisky` and `failOnWarning` attributes to `phpunit.xml.dist`
- PHPStan level 8 with baseline
- Expanded test coverage (106 tests, 324 assertions)
- Code style alignment — variable naming, Yoda conditions, explicit comparisons
- Standardized `.dev/` infrastructure (Dockerfile, docker-compose, entrypoint, pre-commit, utility.sh, .profile)
- Removed `squizlabs/php_codesniffer` (using php-cs-fixer only)
- Renamed `phpunit.xml` to `phpunit.xml.dist`
- `$COMPOSER_DEV_MODE` variable quoted in `composer.json` hook script
- Removed `@todo` comments and trivial inline comments
- `DateTime` → `DateTimeImmutable` in `FileStorage`
- `FieldDto` — added `$hasOldValue` constructor parameter and `hasOldValue()` method
- `AbstractCommand` — extracted duplicated `execute()` logic from `CreateCommand`/`UpdateCommand` into a template-method pattern
- Removed 2 resolved entries from `phpstan-baseline.neon`

### Added

- PHPStan level 8 with baseline

## [v3.0.4] - 2026-03-20 - Correct DateTime Storage Type and Tighten Return Types

### Fixed

- `Storage::getTransactionId()` — uses `DateTimeImmutable` and `Types::DATETIME_IMMUTABLE` instead of mutable `DateTime` / `Types::DATE_MUTABLE`, matching the schema definition
- `Configuration::getIgnoredFields()` — return type tightened from `?array` to `array`
- `Annotation\EntityDto::getClass()` and `getIgnoredFields()` — return types tightened from `?string` / `?array` to non-nullable equivalents
- `Auditor::createAuditEntities()` — added `isset($this->auditedEntities[$entityDto->getClass()])` guard before array access
- `DoctrineSchemaListener::updateType()` — `void` return type declared
- `ThrowTrait::throw()` — logs `$throwable->getTraceAsString()` instead of `$throwable->getTrace()` to avoid leaking sensitive objects into logs

## [v3.0.3] - 2026-03-19 - Enforce Logger Contract in ThrowTrait

### Fixed

- `ThrowTrait` — declares `abstract protected function getLogger(): ?LoggerInterface;` so consumers must provide a logger explicitly instead of relying on an implicit property
- `Auditor::getLogger()`, `Storage::getLogger()`, `FileStorage::getLogger()` — concrete implementations added, returning the configured logger

## [v3.0.2] - 2026-03-19 - Guard Null Field in DoctrineSchemaListener and Validate lastInsertId

### Fixed

- `DoctrineSchemaListener::updateSchema()` — `null` guard added after `getFieldForColumn()` so a missing field no longer dereferences `null`
- `Storage::getTransactionId()` — validates `lastInsertId()` is not `false`, `null`, `'0'`, or `0` before casting to `int`; throws `Exception` on invalid value

## [v3.0.1] - 2026-03-19 - Move Dev Scripts to .dev and Update Dependencies

### Changed

- Moved development scripts directory from `dev/` to `.dev/` (Docker config, git hooks, shared shell utilities, `.profile`, `.env`)
- Updated `dc`, `composer.json` scripts, and pre-commit hooks to reference the new `.dev/` location
- `composer.json` — homepage URL corrected to match the GitHub repository URL
- `composer.lock` refreshed via `composer update`

## [v3.0.0] - 2026-03-18 - Introduce Operation Enum, Old/New Value Tracking, and Extras on TransactionDto

### Breaking Changes

- `AbstractEntityDto::getOperation()` — return type changed from `?string` to the new `Operation` enum
- Removed `AbstractEntityDto::OPERATION_DELETE`, `OPERATION_INSERT`, `OPERATION_UPDATE`, and `OPERATIONS` — replace with `Operation::Delete`, `Operation::Insert`, `Operation::Update`
- `FileStorage` JSONL format — each entity now carries an `operation` field; tracked updated values are serialized as `{"old": ..., "new": ...}` instead of a plain value
- `AbstractCommand::__construct()` — requires `AnnotationReadService` as the 4th constructor argument
- Schema create/update commands process only entities marked with `#[Auditable]`

### Changed

- README updated to reflect the v3 API (enum, old/new value format, command constructor changes)
- Code-style sweep: explicit comparisons, tightened return types, dead code removed

### Added

- `Operation` enum (`Delete`, `Insert`, `Update`) replacing the previous string constants
- Old/new value tracking for UPDATE operations — `FieldDto::getOldValue()` and `FieldDto::getValue()` carry the pair; serialized to storage accordingly
- Optional `extras: array` on `TransactionDto` for custom metadata propagation
- `AnnotationReadService` internal cache (`$entityDtoCache`) to avoid repeated reflection reads for the same class
- `AbstractCommand::getAuditedSourceMetadatas()` — filters Doctrine metadata to `#[Auditable]` entities only

## [v2.1.1] - 2026-03-26 - Fix Storage Logger Wiring and Pre-commit Hook Paths

### Fixed

- `PrecisionSoftDoctrineAuditExtension::defineStorageDoctrine()` and `defineStorageFile()` — read `logger` from the `$storage` config instead of an undefined `$auditor['logger']`, so logger wiring for configured storages no longer triggers a fatal error
- Pre-commit hook — path/permission issues corrected
- `composer.json` — version field corrected from `v2.1.0` to `v2.1.1`

### Added

- `PrecisionSoftDoctrineAuditExtensionTest` — coverage for Doctrine storage, file storage, custom storage definitions, logger wiring, invalid-configuration cases, auditor service definition, and schema command registration

## [v2.1.0] - 2025-01-06 - Widen symfony-console Constraint to 2.*

### Changed

- `composer.json` — `precision-soft/symfony-console` constraint widened to `2.*` alongside existing `1.*`

## [v2.0.0] - 2024-11-24 - Add DBAL 4 Support and AuditOperationType Registration

### Fixed

- `CreateCommand`, `UpdateCommand`, `DoctrineSchemaListener` — minor alignment fixes to keep the schema pipeline working under DBAL 4

### Changed

- `composer.json` — `doctrine/dbal` constraint widened to allow `^4.0`
- `.php-cs-fixer.dist.php` — configuration normalized for the v2 code-style baseline

### Added

- `AuditOperationType` — DBAL type registration for the audit operation column

## [v1.0.0] - 2024-09-17 - Initial Public Release

### Added

- `Auditor` service — `onFlush()` / `postFlush()` pipeline that records entity inserts, updates, and deletes
- `Attribute\Auditable` and `Attribute\Ignore` — opt-in attributes for marking audited entities and excluded fields
- `AnnotationReadService` — reflection-based reader returning `EntityDto` instances for decorated classes
- DTOs: `AbstractEntityDto`, `AuditorDto`, `FieldDto`, `StorageDto`, `TransactionDto`
- Storage backends: `Storage` (Doctrine DBAL) and `FileStorage` (JSONL), both implementing the storage contract
- `DoctrineSchemaListener` — extends the ORM schema with audit tables at generation time
- `CreateCommand` and `UpdateCommand` — console commands managing the audit schema
- `PrecisionSoftDoctrineAuditExtension` + `Configuration` — Symfony DI integration and config tree

### Notes

- Initial public release of `precision-soft/symfony-doctrine-audit`

[Unreleased]: https://github.com/precision-soft/symfony-doctrine-audit/compare/v4.0.0...HEAD

[v4.0.0]: https://github.com/precision-soft/symfony-doctrine-audit/compare/v3.5.0...v4.0.0

[v3.5.0]: https://github.com/precision-soft/symfony-doctrine-audit/compare/v3.4.4...v3.5.0

[v3.4.4]: https://github.com/precision-soft/symfony-doctrine-audit/compare/v3.4.3...v3.4.4

[v3.4.3]: https://github.com/precision-soft/symfony-doctrine-audit/compare/v3.4.2...v3.4.3

[v3.4.2]: https://github.com/precision-soft/symfony-doctrine-audit/compare/v3.4.1...v3.4.2

[v3.4.1]: https://github.com/precision-soft/symfony-doctrine-audit/compare/v3.4.0...v3.4.1

[v3.4.0]: https://github.com/precision-soft/symfony-doctrine-audit/compare/v3.3.1...v3.4.0

[v3.3.1]: https://github.com/precision-soft/symfony-doctrine-audit/compare/v3.3.0...v3.3.1

[v3.3.0]: https://github.com/precision-soft/symfony-doctrine-audit/compare/v3.2.3...v3.3.0

[v3.2.3]: https://github.com/precision-soft/symfony-doctrine-audit/compare/v3.2.2...v3.2.3

[v3.2.2]: https://github.com/precision-soft/symfony-doctrine-audit/compare/v3.2.1...v3.2.2

[v3.2.1]: https://github.com/precision-soft/symfony-doctrine-audit/compare/v3.2.0...v3.2.1

[v3.2.0]: https://github.com/precision-soft/symfony-doctrine-audit/compare/v3.1.1...v3.2.0

[v3.1.1]: https://github.com/precision-soft/symfony-doctrine-audit/compare/v3.1.0...v3.1.1

[v3.1.0]: https://github.com/precision-soft/symfony-doctrine-audit/compare/v3.0.4...v3.1.0

[v3.0.4]: https://github.com/precision-soft/symfony-doctrine-audit/compare/v3.0.3...v3.0.4

[v3.0.3]: https://github.com/precision-soft/symfony-doctrine-audit/compare/v3.0.2...v3.0.3

[v3.0.2]: https://github.com/precision-soft/symfony-doctrine-audit/compare/v3.0.1...v3.0.2

[v3.0.1]: https://github.com/precision-soft/symfony-doctrine-audit/compare/v3.0.0...v3.0.1

[v3.0.0]: https://github.com/precision-soft/symfony-doctrine-audit/compare/v2.1.1...v3.0.0

[v2.1.1]: https://github.com/precision-soft/symfony-doctrine-audit/compare/v2.1.0...v2.1.1

[v2.1.0]: https://github.com/precision-soft/symfony-doctrine-audit/compare/v2.0.0...v2.1.0

[v2.0.0]: https://github.com/precision-soft/symfony-doctrine-audit/compare/v1.0.0...v2.0.0

[v1.0.0]: https://github.com/precision-soft/symfony-doctrine-audit/releases/tag/v1.0.0
