# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

[Unreleased]: https://github.com/precision-soft/symfony-doctrine-audit/compare/v3.4.2...HEAD

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
