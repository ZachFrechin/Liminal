# Liminal — assistant notes

Modular ERP core, PHP 8.4, no framework. `src/` is the kernel (config,
container, HTTP pipeline, registries), `libs/` are the technical capabilities
(System, Database, Security, Rendering, Module), `modules/` are the business
verticals (Authentication is the reference). Everything extends the system
through registries filled at boot, then frozen; libs and modules expose
services through `DefinitionProvider` definitions collected before the
container is built — a module overrides a lib's contract by ordinary
last-wins layering, never by special case.

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
php bin/liminal authentication:user:create <email>   # hidden prompt ×2 — NO --password option, ever
php bin/liminal authentication:role:grant <email> <role>  # wires an EXISTING role, creates nothing
```

Modules: implement `Module` (name/version/migrationNamespace), declare in
app.modules, name every route `<module>.…` (the boot enforces it — that prefix
is the gate's key). Shape always boots; installed/enabled is database state
that boot never consults, and the module gate enforces it per request —
**except public routes**, which bypass the gate (pre-auth = pre-company;
gating them would make `module:disable` a lockout). Module rules from 5a:
request-path security reads are DBAL, never ORM (the company switch clears
the EM after auth, so auth-time entities detach by construction);
administration tables are never CompanyScoped; migration order across
namespaces is contribution order, and a migration touching another
namespace's table guards with `abortIf`, never `skipIf`.

Security: routes are protected unless `public: true`. Middleware order is HTML
errors (−950) → session (−900) → view context (−850) → auth (100) → CSRF (200)
→ company switch (300) → module gate (400). Two rules with teeth: **a failed
login must return a response, never throw** (the error handler is outermost, so
an exception unwinds past the session middleware and persists nothing), and
**hydrated sessions persist by UPDATE, never upsert** — a blind upsert
resurrects sessions killed by logout, regeneration or GC.

Rendering: templates and translations are registry contributions (Twig resolves
a namespace first-hit-wins, so the registry serves latest-first — a module
shadows a lib). `strict_variables` on, no `|raw` anywhere. Content degrades
(absent flash, missing translation key), wiring fails loud (no session for a
CSRF token, malformed catalogue). **Error templates carry no form**: they render
on the unwind path where the session is never persisted. Flashes are catalogue
keys, translated by the layout at render time.

Every commit: `type(scope): imperative subject`, body explains why,
`composer check` green. Integration tests skip without a reachable DSN — run
them with the database up before claiming database-touching work done.

Two traps with teeth: the console resolves every registered command eagerly,
so command constructors must never inject Connection, EntityManagerInterface
or SettingsService — inject the deferred-connection services (MigrationRunner,
DatabaseHealth, FirstCompanySeeder) instead. And middleware priorities are
lower = outer between the anchors (-1000 error handler, 0 router, 1000
dispatcher); boot refuses anything outside them.
