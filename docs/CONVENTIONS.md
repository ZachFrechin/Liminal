# Conventions

The single coding standard for Liminal. Everything here is enforced either by
tooling (`composer check`) or by review; when a rule and the tooling disagree,
fix the tooling. The standard applies identically to `src/`, `libs/`, `tests/`,
`config/`, `public/` and `bin/`.

## Language

Everything is English: code, docblocks, comments, commit messages, README and
docs. No exceptions.

## Baseline

- PHP 8.4+, `declare(strict_types=1);` in every file.
- PER-CS 2.0 via PHP-CS-Fixer (`composer lint:fix` is authoritative).
- PHPStan level max, no baseline, no `@phpstan-ignore`. Fix causes, not
  reports: prove types with `instanceof`/`assert*()` narrowing, never with
  `@var` overrides or casts.
- PSR interfaces at every boundary (PSR-3, 7, 11, 15, 17). No framework.

## Classes

- `final` by default. The only non-final classes are `abstract` bases and
  Doctrine entities.
- `final readonly class` with constructor property promotion whenever every
  property is immutable; per-property `readonly` only when the class must stay
  mutable (Kernel, Router) or a parent forbids it (Symfony Command).
- Static-only utility classes (`Env`, `Dsn`) carry a `private function
  __construct() {}` instantiation guard.
- Value objects validate in their constructor and throw
  `InvalidArgumentException`; impossible states get private constructors plus
  named factories (`RouteMatch`).
- Template Method over overridable core behaviour: `AbstractRegistry::freeze()`
  is final, `onFreeze()` is the hook.

## Docblocks

- Every class, interface, trait and enum has a docblock. One sentence of role
  minimum; the *why* whenever one exists. Docblocks explain intent and
  constraints the signature cannot carry — never restate the signature.
- Public methods are documented when their behaviour is not obvious from name
  and types; trivial accessors are not.
- `@throws` is mandatory on any method that throws directly, with a short
  "when …" clause.
- Array types always use generics (`list<T>`, `array<K, V>`) — never `T[]`.
  Single-annotation docblocks may be one-liners (`/** @var list<Route> */`).
- Comments inside methods state constraints the code cannot show (ordering
  guarantees, driver quirks, security reasoning) — never what the next line
  does.

## Exceptions

- Every deliberate core exception implements the `LiminalException` marker.
- SPL parent carries the semantics: `LogicException` = developer contract
  violation (frozen writes, duplicates, wiring mistakes); `RuntimeException` =
  environment or data condition (missing configuration, cross-company access).
- Dedicated final classes with named constructors (`::for()`, or descriptive
  verbs when a class covers several ways to fail — see `KernelException`).
  Raw SPL throws are allowed only for value-object validation
  (`InvalidArgumentException`) and unreachable internal invariants
  (`LogicException`).
- Messages: built with `sprintf`, dynamic identifiers quoted (`"%s"`), full
  sentence, trailing period. Messages that reach HTTP clients
  (`HttpException`) must be safe to show.
- Wrapping requires `$previous`.

## Naming

- Core services and value objects use bare-noun accessors (`statusCode()`,
  `currentId()`, `all()`); Doctrine entities use `get*/set*` (ORM convention).
- Registries: `add()` to contribute, `all()` to enumerate, `has()`/specific
  reads where lookup is part of the contract. `RegistryCollection::get()` and
  the router DSL verbs (`get()`, `post()`, …) are each idiomatic in their own
  context; the double meaning is accepted and documented here.
- The company axis says `Company` (`CompanyScoped`, `company_id`,
  `core_company`); "entity" alone always means a Doctrine entity.
- Tables: `<module>_<name>` with the module prefix as the boundary
  (`core_*`); no global prefix ever.

## Registries

- Contributions only during boot; `freeze()` closes everything, including the
  collection itself.
- Keyed registries refuse duplicates (`DuplicateContributionException`).
  `MenuRegistry` is the documented exception (no natural key).
- Contributors are manifests: plain-`new`, no required constructor arguments.
  Services go through `DefinitionProvider::definitions()` — lazy, no I/O,
  kernel-structural ids reserved, later libs (then modules) win elsewhere.
- Middleware priorities: lower = outer; kernel anchors at −1000 (error
  handler), 0 (router), 1000 (dispatcher). Suggested bands: −999…−1
  pre-routing (sessions, CORS), 1…999 post-routing (auth, CSRF, company
  switch). Boot refuses anything sorted outside the anchors, by name.
- ModuleRegistry is kernel-owned and constructor-filled — the one registry
  with no add(): a manifest arriving during contribute() would be one whose
  definitions() never reached the already-built container. Modules never
  write it; the kernel does, from app.modules.
- Modules declare their shape (routes, entities, migrations) at every boot
  regardless of installed/enabled state: shape is global, state is per
  company, and boot never consults the database. Route names carry the
  module-name prefix (`<module>.…`) — the module gate's key, enforced at boot.

## Rendering

- Templates and translations are contributions: register a namespace or a
  catalogue file, never scan a directory at boot. Twig resolves a namespace
  first-hit-wins, so the registry serves latest-contribution-first — a module
  shadows a lib.
- `strict_variables` stays on, and no template uses `|raw`.
- View helpers split failures by class: content degrades (absent flash → null,
  missing translation key → the key), wiring fails loud (no session for a CSRF
  token, malformed catalogue, orphaned menu parent).
- An error page renders on the unwind path, where the session is never
  persisted: **error templates must never carry a form**, because a token
  minted there would reach the HTML and never the store.
- Rendering's sanctioned lib edges: → Security, → Module, → Database. Nothing
  consumes Rendering.

## Request security

- Routes are protected unless declared `public: true`. Middleware bands: HTML
  errors (−950), session (−900), view context (−850), authentication (100),
  CSRF (200), company switch (300), module gate (400).
- **A failed login returns a response; it never throws.** The error handler is
  the outermost middleware, so an exception unwinds past the session
  middleware and nothing is persisted — deliberate for error traffic, fatal
  for state a failed attempt needs to keep (flash messages, future throttling
  counters).
- **Hydrated sessions persist by UPDATE, never by upsert**, and a zero-row
  result is accepted silently: deletion must beat concurrent writers, or a
  parallel request resurrects a session killed by logout, regeneration or GC.
- Refusals hide what they can: a disabled module's route answers the same 404
  a nonexistent path does. Never a status that confirms existence.
- Session values (company preference included) are untrusted input even though
  only our code writes them: validate against the user's entitlements.
- A module migration may touch a lib's table in exactly one sanctioned case:
  the authentication module adding the `core_session.user_id` foreign key,
  which cannot exist before `core_user` does.

## Known gaps (recorded, not forgotten)

- `Cache-Control: no-store` is emitted on cookie-issuing responses only. A
  policy for authenticated pages in general is a phase-5 rendering decision.
- `ResponseEmitter` does not strip bodies from HEAD responses — a pre-existing
  kernel gap; the fix is to pass the request method into `emit()`.
- ~~`Gate::authorize()`~~ closed in phase 5a: `RequestGate` is the throwing
  companion, in the lib so no module depends on another module.

## Console commands

- The console Application resolves every registered command eagerly, so a
  command constructor must never inject `Connection`,
  `EntityManagerInterface` or anything that resolves them (e.g.
  `SettingsService`) — a DSN-less checkout must still run `doctor`. Inject
  the deferred-connection services instead (`MigrationRunner`,
  `DatabaseHealth`, `FirstCompanySeeder`, `ModuleManager` are the
  precedents, all built on `DeferredConnection::resolver()`).
- Diagnostics never mutate what they inspect (`doctor`, `migrate:status`);
  `install` is the one command allowed to write.

## Configuration and environment

- `getenv()` is called in `Support\Env` only; config files are the only
  callers of `Env`; code reads `Configuration` only.
- A config key exists only if some code reads it — no speculative keys.
- Requested config files are required files; typed reads without defaults
  throw. Absent env vars fall back (they are optional overrides by
  definition); the required/optional decision lives in the config file.
- Tests read `LIMINAL_TEST_DSN` themselves; application config never touches
  test variables.

## Tests

- One test class per subject, `#[CoversClass]` on unit tests,
  `#[CoversNothing]` on integration tests.
- Names state behaviour, not methods: `testFlushingAReassignedEntityIs
  RefusedBeforeAnySqlRuns`, not `testOnFlush`.
- A docblock on the test explains *why* the guarantee matters when it is not
  obvious.
- Integration tests run against real MariaDB and skip without a reachable
  `LIMINAL_TEST_DSN`; anything faking the database proves nothing about SQL
  filters, DDL or NULL-in-unique-index semantics.
- Every bug fix lands with the test that would have caught it.

## Commits

- `type(scope): imperative subject` — types: feat, fix, refactor, test, docs,
  chore, ci. The body explains the why; every commit leaves `composer check`
  green.

## Tooling map

| Surface | Tool |
|---|---|
| Formatting | PHP-CS-Fixer (PER-CS 2.0), covers src, libs, tests, config, public, bin |
| Static analysis | PHPStan level max + phpstan-doctrine, same coverage |
| Tests | PHPUnit 11, `unit` and `integration` suites |
| CI | validate --strict, audit, lint, stan, unit, integration on MariaDB 10.11 |
