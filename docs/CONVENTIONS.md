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

## Module authoring

The authentication module is the reference implementation; these rules are
what it proves.

- A module owns its slugs: route names prefixed `<module>.`, the template
  namespace `@<module>`, its own translation catalogue and its own migration
  namespace. Nothing is shared, nothing is scanned.
- **Public routes are not module-gated.** A public route is pre-authentication
  and therefore pre-company, so "enabled for your company" has no subject —
  and gating it would let `module:disable` lock everyone out of sign-in. The
  documented cost: a module wanting a *gated* public page must gate inside
  its handler.
- **Request-path security reads never use the ORM.** The company switch
  middleware clears the EntityManager once per request, *after*
  authentication ran: any entity hydrated at auth time is detached one
  middleware later, by construction. Plain DBAL, and a value object
  (`Identity`) for the authenticated user — the entities exist for
  administration screens only.
- **Administration tables are never `CompanyScoped`.** Two independent
  disqualifications: an anonymous login request would be fenced into the
  bootstrap company (a user of only company 2 could never sign in — and it
  would work on every dev machine where everyone is in company 1), and the
  scope trait's write-once stamp would stop an admin in company 1 from
  creating a grant for company 2. Cross-company by nature is not an exception
  to scoping; it is the reason the interface is opt-in.
- Migration order across namespaces is contribution order
  (`ContributionOrderComparator`): libs in `app.libs` order, then modules in
  `app.modules` order; an unregistered namespace sorts last. A migration that
  touches another namespace's table (the sanctioned FK case) must still guard
  with `abortIf`, **never `skipIf`** — skipping records the version as
  executed and the statement never runs.
- A module may read the config section of the lib whose contract it
  implements (the throttle reads `security.login_throttle`): the section
  belongs to the contract, not the binding. It may not read another module's
  section — there is no module→module dependency mechanism, on purpose.
- Handlers on the login path follow the house rule with teeth: **failure is a
  flash plus a redirect, never a throw.** Flash values are catalogue keys,
  translated by the layout at render time; free text degrades through the
  missing-key rule unchanged. One sanctioned deviation: a response whose body
  IS a one-time secret (generated passwords) renders directly from the POST
  with `no-store` — the secret must never transit the session or any store.
- **Say it out loud: `authentication.user.manage` held in any ONE company is
  instance-wide administration and escalation-equivalent to everything** —
  the admin tables are unscoped by design, and grant-add can assign any role
  in any company, `admin` included. `authentication.role.manage` protects
  role *definitions*, a different blast radius — it does not close that
  path. Nobody reading the permission list should believe otherwise.
- Permission checkboxes and any other stored-code write are filtered against
  the `PermissionRegistry`; stored codes the registry no longer declares are
  inert (the resolver only joins), render as "unknown", and must never be
  passed through `Gate::allows`, which throws on undeclared codes.
- Company and role codes are immutable after creation and follow a grammar
  (`[A-Z][A-Z0-9_]{0,31}` for companies — a policy, not an inherited
  constraint; `[a-z][a-z0-9_]{0,63}` for roles, mirroring module slugs).

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

- `Cache-Control: no-store` is emitted on cookie-issuing responses only and on
  the authenticated pages that opt in (`/account`, `/users`); a general policy
  for authenticated pages is still open.
- `ResponseEmitter` does not strip bodies from HEAD responses — a pre-existing
  kernel gap; the fix is to pass the request method into `emit()`.
- ~~`Gate::authorize()`~~ closed in phase 5a: `RequestGate` is the throwing
  companion, in the lib so no module depends on another module.
- The menu has no "active item" flag: the view context is primed at −850,
  before the router matched anything. The obvious implementation is wrong, not
  merely missing — it needs a second, post-router middleware.
- ~~The three 5a obligations~~ closed in 5b: company creation enables the
  installed modules transactionally, the "no access" badge makes the
  no-grant-anywhere user visible, and the `admin` role code can be neither
  renamed (structurally — the code is never an input) nor deleted (refused
  in the screen AND the service).
- Company deletion does not exist on purpose: the foreign keys silently
  cascade grants, settings and module enablement away — it will be an
  audited administrative service, not a button.
- The administration screens have no pagination; fine until a real
  instance proves otherwise.
- Administrative mutations are not audited: `core_auth_event` covers the
  login path only, and extending the lib's `AuthEvent` is its own decision,
  not a side effect of screens.
- Reactivating a user silently resumes their old sessions: the forced-logout
  401 unwinds past persist, so the session row keeps its `user_id`. Coherent
  — and the reason a password RESET deletes the rows instead.
- The switcher trap: with the authentication module disabled for the CURRENT
  company, `/account` and `/switch-company` are 404 and the browser offers no
  way out — `module:enable authentication <company>` is the escape. Hosting
  the switch route in the security lib would not help while the only form
  lives on /account, and error pages can never carry a form.
- "Must change password at first login" waits for the mailer lib: without a
  reset flow there is nowhere to send anyone.

## Console commands

- The console Application resolves every registered command eagerly, so a
  command constructor must never inject `Connection`,
  `EntityManagerInterface` or anything that resolves them (e.g.
  `SettingsService`) — a DSN-less checkout must still run `doctor`. Inject
  the deferred-connection services instead (`MigrationRunner`,
  `DatabaseHealth`, `FirstCompanySeeder`, `ModuleManager` are the
  precedents, all built on `DeferredConnection::resolver()`).
- Diagnostics never mutate what they inspect (`doctor`, `migrate:status`);
  writes belong to the commands whose name says so (`install`,
  `authentication:user:create`, …).
- A command that needs a secret prompts for it hidden — **never an option or
  argument**, because argv lands in shell history and `ps` output. A
  non-interactive run is refused explicitly rather than served by an invented
  secret.
- `CommandResolutionTest` enforces the eager-resolution rule: it resolves
  every registered command on a DSN-less checkout, so a constructor-injected
  `Connection` fails in CI naming the class.

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
