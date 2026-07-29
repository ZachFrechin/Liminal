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
php bin/liminal module:install <name>            # module migrations + record
php bin/liminal module:enable <name> <company>   # per-company state (module:disable, module:list)
php bin/liminal session:gc                       # sweep expired sessions (cron when gc_percent=0)
```

Modules: implement `Module` (name/version/migrationNamespace), declare in
app.modules, name every route `<module>.…` (the boot enforces it — that prefix
is the gate's key). Shape always boots; installed/enabled is database state
that boot never consults, and the module gate enforces it per request.

Security: routes are protected unless `public: true`. Middleware order is
session (−900) → auth (100) → CSRF (200) → company switch (300) → module gate
(400). Two rules with teeth: **a failed login must return a response, never
throw** (the error handler is outermost, so an exception unwinds past the
session middleware and persists nothing), and **hydrated sessions persist by
UPDATE, never upsert** — a blind upsert resurrects sessions killed by logout,
regeneration or GC.

Every commit: `type(scope): imperative subject`, body explains why,
`composer check` green. Integration tests skip without a reachable DSN — run
them with the database up before claiming database-touching work done.

Two traps with teeth: the console resolves every registered command eagerly,
so command constructors must never inject Connection, EntityManagerInterface
or SettingsService — inject the deferred-connection services (MigrationRunner,
DatabaseHealth, FirstCompanySeeder) instead. And middleware priorities are
lower = outer between the anchors (-1000 error handler, 0 router, 1000
dispatcher); boot refuses anything outside them.
