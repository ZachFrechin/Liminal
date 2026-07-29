# Liminal — assistant notes

Modular ERP core, PHP 8.4, no framework. `src/` is the kernel (config,
container, HTTP pipeline, registries), `libs/` are the technical capabilities
(System, Database), modules arrive in phase 2. Everything extends the system
through registries filled at boot, then frozen; libs expose services through
`DefinitionProvider` definitions collected before the container is built.

**The norm is [docs/CONVENTIONS.md](docs/CONVENTIONS.md). Read it before
writing any code; it wins over habit.** Highlights: everything English,
`final readonly` by default, PHPStan level max without ignores, dedicated
exceptions with named constructors and the `LiminalException` marker, `@throws`
on direct throws, duplicates refused in registries, `company_id` is write-once.

## Commands

```bash
composer check                  # lint + stan + unit — must stay green
composer lint:fix               # authoritative formatting
docker compose up -d db         # MariaDB 10.11 (LIMINAL_DB_PORT to remap)
LIMINAL_TEST_DSN='mysql://liminal:liminal@127.0.0.1:3306/liminal_test' composer test:integration
php bin/liminal doctor          # environment + registries + database checks
php bin/liminal install         # migrations + first company (needs LIMINAL_DSN)
php bin/liminal migrate         # pending migrations, --module=NS to scope
php bin/liminal migrate:status  # read-only, never creates the metadata table
```

Every commit: `type(scope): imperative subject`, body explains why,
`composer check` green. Integration tests skip without a reachable DSN — run
them with the database up before claiming database-touching work done.

Two traps with teeth: the console resolves every registered command eagerly,
so command constructors must never inject Connection, EntityManagerInterface
or SettingsService — inject the deferred-connection services (MigrationRunner,
DatabaseHealth, FirstCompanySeeder) instead. And middleware priorities are
lower = outer between the anchors (-1000 error handler, 0 router, 1000
dispatcher); boot refuses anything outside them.
