# CHANGELOG

## v3.1.0

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
