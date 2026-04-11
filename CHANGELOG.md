# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [v3.2.3] - 2026-04-11

### Fixed

- `FileStorage::save()` — skip writing when entities list is empty
- `Auditor::save()` — attempt all storage backends even if one fails; first exception is re-thrown after all storages have been tried

### Changed

- `Storage\Doctrine\Configuration` — properties marked `readonly`; added `@param` PHPDoc
- Removed `final` from 9 DTO/Attribute classes (FieldDto, AuditorDto, Auditor\EntityDto, Annotation\EntityDto, Storage\EntityDto, StorageDto, TransactionDto, Auditable, Ignore)
- 4 Mockery-based test classes migrated to `AbstractTestCase` (AuditorTest, AnnotationReadServiceTest, StorageTest, DoctrineSchemaListenerTest)

## [v3.2.2] - 2026-04-10

### Added

- `DoctrineSchemaListener::postGenerateSchema()` — add `extras` column to `audit_transaction` table schema (column was missing from schema generation)

### Fixed

- `Auditor` — track old association field values in audit trail (previously associations always recorded `null` as old value on update)
- `Storage::getTransactionId()` — persist `extras` data in Doctrine storage (was silently ignored)
- `Exception` — removed `final` modifier to allow extension

### Changed

- `DoctrineSchemaListener::postGenerateSchemaTable()` — extract audit table configuration to private `configureAuditTable()` method
- `AnnotationReadService` — replace `empty()` checks with explicit `[] ===` comparisons
- `Storage::save()` / `FileStorage::buildTransaction()` — replace `empty()` checks with explicit `[] ===` / `[] !==` comparisons
- Update `phpstan-baseline.neon`

## [v3.2.1] - 2026-04-09

### Fixed

- `Auditor::onFlush()` — replace `empty()` checks with explicit `[] ===` comparisons for audited entity lists
- `Auditor::createStorageDto()` — throw `Exception` instead of silently skipping when all fields of an entity are ignored
- `Configuration` — replace `empty()` check with explicit `[] !==` comparison for `synchronous_storages` validation
- `DoctrineSchemaListener` — replace `isset($associationMapping->joinColumns)` guard with `instanceof ManyToOneAssociationMapping / OneToOneOwningSideMapping` check for correct owning-side detection
- `DoctrineSchemaListener` — throw `Exception` when a primary key column has been dropped via the ignored fields list

## [v3.2.0] - 2026-04-07

### Breaking Changes

- `AnnotationReadService::getEntityClass()` static method renamed to `resolveEntityClass()` — callers using the static form must update to `resolveEntityClass()` or inject `AnnotationReadServiceInterface` and call the instance method
- `Auditor`, `PrecisionSoftDoctrineAuditExtension`, `DoctrineSchemaListener` — removed `final` modifier to allow extension

### Added

- `AnnotationReadServiceInterface` — new contract with `read()`, `buildEntityDto()`, and `getEntityClass()` methods; `AnnotationReadService` now implements it
- `AnnotationReadService::getEntityClass()` — new instance method implementing `AnnotationReadServiceInterface`

### Fixed

- `DoctrineSchemaListener` — `instanceof` and `\in_array()` now use explicit `true ===` wrappers
- `AnnotationReadService` — simplify attribute reading: replace `foreach` loop with direct `$attributes[0]` access after `empty()` guard
- `DoctrineSchemaListener::postGenerateSchemaTable()` — resolve join column field names by iterating `associationMappings` when direct field lookup returns null

### Changed

- `Auditor` — depends on `AnnotationReadServiceInterface` instead of concrete `AnnotationReadService`; replace `$entity::class` with `getEntityClass()` call for correct proxy class resolution; `fieldMapping['type']` → `fieldMapping->type` (Doctrine 3 object-style mapping); refactor `createAuditEntities()` from `array_map`/`array_filter` to explicit `foreach`
- `DoctrineSchemaListener` — depends on `AnnotationReadServiceInterface`; use `$mapping->columnName` directly (Doctrine 3)
- `AbstractCommand` — depends on `AnnotationReadServiceInterface`; rename `$sql` → `$sqlStatement` in loop
- `PrecisionSoftDoctrineAuditExtension` — replace `switch` with `match` for storage type resolution; replace `empty()` checks with explicit `null === || '' ===`; rename `$storageServiceId` → `$auditorConfigServiceId`
- `ThrowTrait` — cast `$throwable->getCode()` to `(int)` in both constructor calls

## [v3.1.1] - 2026-04-06

### Fixed

- `Auditor` — replace `isset($changeSet[$field])` with `\array_key_exists()` to correctly track nullable field changes from `null` (`isset()` returns `false` for `null` values, causing audit trail data loss)
- `Auditor::postFlush()` — `gc_collect_cycles()` no longer runs on early-return path when no audit work was done
- `AbstractCommand::createSchemaTool()` — accept `$sourceMetadatas` as parameter to avoid duplicate `getAuditedSourceMetadatas()` call
- `Configuration` — remove `isRequired()` from `synchronous_storages` node (defaults via `beforeNormalization`)

### Changed

- `Storage::save()` — wrap in DBAL transaction with `beginTransaction()`/`commit()`/`rollBack()`
- `Storage::saveTransaction()` — `lastInsertId()` cast to `int` with `0 >= $lastId` check
- `DoctrineSchemaListener` — fix `$entityTable` to `$table` variable reference, Yoda comparison style, rename `$t` to `$throwable`
- `ThrowTrait` — rename `$t` to `$throwable`, cast error code to `(int)` in exception constructor
- `AnnotationReadService::getEntityClass()` — replace hardcoded `__CG__` proxy string with `Doctrine\Persistence\Proxy::MARKER`
- `FileStorage` — `Filesystem` and `JsonEncoder` are now class properties instead of per-call instances
- `AuditOperationType` class marked `final`
- `PrecisionSoftDoctrineAuditBundle` class marked `final`
- `Exception` class marked `final`
- `PrecisionSoftDoctrineAuditExtension` — `static::` replaced with `self::` for constants in `final` class
- `DoctrineSchemaListener::updateType()` — replace duplicate switch cases with single `if` condition for `AbstractEnumType`/`AbstractSetType`
- `.dev/docker/entrypoint.sh` — skip `composer install` when `composer.lock` hash matches cached vendor
- Variable naming compliance: `$sqls` -> `$sqlStatements`, `$diff` -> `$missingStorages`, `$data` -> `$originalEntityData`/`$associationData`, `$type` -> `$storageType`/`$fieldType`, `$value` -> `$relatedFieldValue`/`$fieldValue`, `$obj` -> `$throwTraitUser`, `$mock` -> `$annotationReadService`, `$result`/`$first`/`$second` -> descriptive names in tests
- Update `phpstan-baseline.neon`

## [v3.1.0]

### Breaking changes

- Requires `precision-soft/doctrine-type` ^3.0 (was 2.*)
- Requires `precision-soft/symfony-console` ^4.0 (was 3.*)

### Fixed

- `UpdateCommand` — remove extra second parameter from `getUpdateSchemaSql()` and `updateSchema()` (Doctrine ORM 3 accepts only 1 parameter)
- `Storage::saveEntity()` — quote table and column identifiers via `DatabasePlatform::quoteIdentifier()`
- `FileStorage` — use `FieldDto::hasOldValue()` instead of null-checking `getOldValue()` to correctly detect fields with `null` old values
- `DoctrineSchemaListener::postGenerateSchemaTable()` — replace removed `getFieldForColumn()` with manual `fieldMappings` iteration (DBAL 4 compatibility)

### Changed

- Upgrade from PHPUnit 9 to PHPUnit 11.5 via `precision-soft/symfony-phpunit: ^3.0`
- Replace `<coverage>` with `<source>`, `<listeners>` with `<extensions>` in `phpunit.xml.dist`
- Add `failOnRisky` and `failOnWarning` attributes to `phpunit.xml.dist`
- PHPStan level 8 with baseline
- Expanded test coverage (106 tests, 324 assertions)
- Code style alignment — variable naming, Yoda conditions, explicit comparisons
- Standardized `.dev/` infrastructure (Dockerfile, docker-compose, entrypoint, pre-commit, utility.sh, .profile)
- Removed `squizlabs/php_codesniffer` (using php-cs-fixer only)
- Renamed `phpunit.xml` to `phpunit.xml.dist`
- Quote `$COMPOSER_DEV_MODE` variable in `composer.json` hook script
- Removed `@todo` comments and trivial inline comments
- `DateTime` → `DateTimeImmutable` in `FileStorage`
- `FieldDto` — added `$hasOldValue` constructor parameter and `hasOldValue()` method
- `AbstractCommand` — extracted duplicated `execute()` logic from `CreateCommand`/`UpdateCommand` into template method pattern
- Removed 2 resolved entries from `phpstan-baseline.neon`

[v3.2.3]: https://github.com/precision-soft/symfony-doctrine-audit/compare/v3.2.2...v3.2.3

[v3.2.2]: https://github.com/precision-soft/symfony-doctrine-audit/compare/v3.2.1...v3.2.2

[v3.2.1]: https://github.com/precision-soft/symfony-doctrine-audit/compare/v3.2.0...v3.2.1

[v3.2.0]: https://github.com/precision-soft/symfony-doctrine-audit/compare/v3.1.1...v3.2.0

[v3.1.1]: https://github.com/precision-soft/symfony-doctrine-audit/compare/v3.1.0...v3.1.1

[v3.1.0]: https://github.com/precision-soft/symfony-doctrine-audit/compare/v3.0.4...v3.1.0
