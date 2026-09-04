# Symfony Doctrine Audit — example

The product nomenclator of a shop — categories, products, sales channels and discount offers whose every change is answerable months later — built on `precision-soft/symfony-doctrine-audit` and run against MySQL 8.4 and MariaDB 11.8. It is the minimum of code that demonstrates the maximum of the bundle: one kernel, five entities, one service, and a test suite that does with them what an application does. Paths in this file are relative to `.example/`.

The catalogue lives in one database and its trail in a second one. That is the bundle's hard rule, not a preference: audit tables carry the *same names* as the entity tables they mirror, so pointing a doctrine storage at the audited connection would replace the catalogue with its own history.

## What it represents

- `src/CatalogueKernel.php` — a micro-kernel registering `FrameworkBundle`, `DoctrineBundle` and `PrecisionSoftDoctrineAuditBundle`, with two connections and two entity managers (`catalogue`, `trail`) mapping the same classes, one `doctrine` storage, one `file` storage and one auditor with a global `ignored_fields`. So the auditor, both storages and all four commands are wired by the bundle's own extension, the way they are in an application. The four commands are private services, as console commands should be, so the kernel aliases the ones a test drives — which is also how an application reaches one from its own code.
- `src/Entity/Product.php` — the audited heart of the catalogue: a price the trail has to explain, an owning `ManyToMany` to `Channel`, a `ManyToOne` to `Category`, and two columns deliberately kept out of the trail, one per mechanism (`#[Ignore]` on `supplierTerms`, the auditor's `ignored_fields` on `modified`).
- `src/Entity/Category.php`, `src/Entity/Channel.php` — an audited nomenclator node, and a channel that is never audited on its own because it is only ever the target of a collection.
- `src/Entity/AbstractOffer.php` with `DiscountOffer` and `BundleOffer` — joined inheritance: the child that inherits the root's `#[Auditable]` and the child that opts out with `#[Auditable(false)]`.
- `src/Service/Catalogue.php` — the nomenclator's operations. Nothing in it knows about auditing, which is the point of the bundle: an application keeps its own code and gets a trail.
- `src/Service/CatalogueTransactionProvider.php` — the one contract an application must supply: who is changing the catalogue, plus whatever else the trail should answer for.

## What each test shows

| Test file                                   | Library capability demonstrated                                                                                                                                                                                                                                                 |
|---------------------------------------------|---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `tests/Functional/ProductAuditTest.php`     | insert, update and delete of a product with the username and the transaction extras, a delete keeping the last known values, both ignore mechanisms leaving no column in either storage, and the trail holding neither the join table nor an opted-out class                    |
| `tests/Functional/CollectionAuditTest.php`  | an owning `ManyToMany` published and withdrawn with deterministically ordered identifiers, the retirement of the owner auditing the join rows it removes, and `Collection::clear()` doing the same                                                                              |
| `tests/Functional/InheritanceAuditTest.php` | a joined child audited through its own table and the root under one transaction id, and a child that opts out being absent from the trail and from the audit schema                                                                                                             |
| `tests/Functional/StorageParityTest.php`    | the doctrine table and the jsonl file describing the same history in their two shapes — a column per field against an `old`/`new` pair — with a date that carries its offset                                                                                                    |
| `tests/Functional/AuditCommandTest.php`     | the four commands: `schema:create` (used by the harness itself) and `schema:update` finding no drift, `audit:read` by identity, by operation and by cursor, and `audit:purge` walking the jsonl one bounded batch per run, dry by default, leaving the doctrine trail untouched |

Three things worth knowing before writing a scenario of your own. The audit table keys a value by its **column** name while the jsonl record keys it by the entity's **field** name, so the same change reads `price_in_cents` in one and `priceInCents` in the other. The ORM clears a removed entity's generated identifier once the delete has run, so a test that wants to look its trail up afterwards has to read the id before retiring it. And the schema commands only print their SQL unless `--force` is passed, which is what makes them safe to run against production.

## How to run

From the repository root, with the databases up:

```bash
.dev/validate/all.sh --example
```

or by hand, inside the dev container:

```bash
cd .example && composer install && composer check
```

`composer.lock` is not committed: the example installs the bundle from the working tree through a path repository, so it always tests the code as it stands. `composer test` runs with `--fail-on-skipped`, so a database that is not there is a failure, not a skip. The root's `composer cs-check` covers this directory; `phpstan.neon` includes `../.dev/phpstan/rules.neon`, so the house rules apply here too. The directory is `export-ignore`d and never reaches a consumer's `vendor/`.
