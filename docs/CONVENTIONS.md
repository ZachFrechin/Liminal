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
- **Business CRUD goes through the ORM** — the company fence (filter, stamp,
  postLoad guard, write-once flush gate) lives nowhere else, and a DBAL read
  of a CompanyScoped table bypasses all of it. This is the exact inverse of
  the security/administration rule (DBAL, never ORM), and both are
  deliberate: two domains, two rules, stated side by side.
- **Business repositories narrow every read to the CURRENT company** in DQL
  (`WHERE t.companyId = :current`), byId() included — a URL into another
  accessible company 404s. The filter stays parameterized on the accessible
  set: it is the security boundary, not the work context, and the phase-1
  tests pin it. `ThirdpartyRepository` is the reference.
- Search uses `LIKE` with an **explicit `ESCAPE`** (the escape char is `!` —
  a backslash would have to survive PHP, DQL and SQL quoting in agreement)
  and escapes the user's `%`/`_`/escape-char inside the bound value: bound
  parameters neutralize nothing.
- Pagination: COUNT first, clamp the page into `[1, max(1, pages)]`, always
  give ORDER BY a unique tiebreaker (`, id`). Module-local until a second
  list needs it — then it gets hoisted, not before.
- Permission code grammar: `<module>.<entity>.<verb>` — collapsed to
  `<module>.<verb>` when the module is named after its central entity
  (`thirdparty.read`, not `thirdparty.thirdparty.read`). A read/write split
  means **manage presumes read**: every handler authorizes read first,
  writes authorize manage in addition; there is no permission inheritance.
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

## Hooks and triggers

- **Declare-then-listen**: the contributor that dispatches declares the name;
  consumers subscribe by container service id. Grammar at the registry —
  hooks are ≥3 dotted lowercase segments (`invoice.total.compute`), triggers
  SCREAMING_SNAKE ≤64 (the audit column's width: unstorable fails the boot).
  No module prefix is imposed; a declaration collision breaks the boot
  loudly, which IS the coordination. Subscriptions are validated at FREEZE,
  never at listen() — boot order stays irrelevant.
- The two contracts never blur: a hook listener's exception PROPAGATES (it
  is business logic); a trigger listener's failure — miswiring included —
  is caught and logged with the listener's name, and the next one runs.
- Trigger payloads are typed `array<string, scalar|null>`: identifying
  facts only (ids, codes, emails), **never a secret** — they land verbatim
  in the audit trail. `TriggerEvent::companyId` is the company the event
  BELONGS to: the working context unless the fire point knows better
  (console commands pass `--company`; grants pass the granted company).
- **Post-commit is a convention the fire points must honor**, not
  machinery: a fire inside an open transaction would enroll listeners'
  writes in it. The audit is therefore best-effort by construction — a
  future audit-or-abort requirement is a hook, not a trigger.
- A trigger listener must NOT fire triggers (unbounded recursion; a written
  rule, not a depth counter). A hook listener MAY filter through other
  hooks — composition, and propagation keeps it honest.
- The audit listener sits at an ANCHOR priority
  (`SecurityContributor::AUDIT_PRIORITY = -1000`): the forensic row exists
  before any other listener can kill the process. `core_audit_event`
  carries **zero foreign keys** on purpose — a lib cannot reference a
  module's table, and an audit stores historical facts, not live
  references.
- Sanctioned lib edge: Security → Hook (the audit listener implements
  TriggerListener; `AuthenticatedTriggerScope` overrides the lib's inert
  `TriggerScope` default). The Hook lib depends on the kernel and PSR only.
- The console now provisions `app.log_dir` like HTTP always has: commands
  injecting `Triggers` resolve the logger at console boot.

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
  minted there would reach the HTML and never the store. The shell enforces it
  structurally: its two form regions live in the `sidebar_actions` and
  `topbar_actions` blocks, and `error.html.twig` overrides both empty.
- **Layout-level `{% set %}` shares the child templates' context** (Twig
  inheritance renders child blocks inside the parent's flow), so every
  variable the layout sets carries a `_liminal_` prefix. An unprefixed
  `companies` once shadowed the companies module's own context and 500'd its
  list page — the prefix is the fence.
- Rendering's sanctioned lib edges: → Security, → Module, → Database. Nothing
  consumes Rendering.

## Design system

- The reference is the claude.ai/design project; the tree carries the
  implementation under `public/assets/` — hand-authored files are tracked,
  `public/assets/build/` stays reserved for future compiled artifacts.
- **Zero CDN at runtime.** Fonts are vendored woff2 (OFL texts beside the
  binaries, as the license requires); icons are vendored Lucide glyphs (ISC)
  in `IconSet` — a dependency-free map where an unknown name throws, because a
  template naming a glyph that was never vendored is wiring, not content. The
  emitted SVG ends exactly at `</svg>`: menu labels are pinned `>Label</a>`
  with the glyph right before them.
- **Components consume semantic aliases** (`--surface-*`, `--text-*`,
  `--action-*`, the semantic family scales), never a raw `--n-*` neutral.
  `TokenDriftTest` makes the contract mechanical.
- **Only components with a real consumer get a Twig macro** (`banner`,
  `empty_state` in `components.html.twig`, imported as `ui`). Toast, tooltip
  and dialog ship CSS with their markup proven on the design fixture page;
  they gain macros when a production surface earns them. No dismiss buttons
  server-side: a PRG flash dies on the next request by itself.
- Flashes render as banners in BOTH shell faces — two tests assert flashes on
  anonymous pages. Danger alone is `role="alert"`; everything else is polite
  `role="status"`.
- The shell hides company-switch forms when the authentication module is
  disabled for the working company (`ModuleManager::isEnabled`) — advertising
  a POST that answers 404 is the one lie the chrome could tell. Every module
  route the shell names sits behind `route_exists()`, so module-less checkouts
  (the rendering fixtures) keep rendering.

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
- ~~Administrative mutations are not audited~~ closed in phase 7: every
  fired trigger lands in `core_audit_event` through the catch-all listener,
  and fourteen fire points cover the admin surface. The login path keeps
  its own `core_auth_event` (richer: ip/UA), no duplication.
- Audit retention/purge does not exist yet — and the payloads carry emails
  (PII) while the table is append-only: a WHEN, not an IF. `session:gc` is
  the precedent for the future `audit:prune`.
- No `/audit` screen yet (a 7b candidate); the table is queryable and the
  doctor counts declarations.
- `TriggerEvent` carries no ClientContext (ip/UA): libs cannot read request
  attributes — the same rationale that shaped the Gate — and the login path
  already captures them where they matter.
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
- Thirdparty deletion is unguarded while nothing references thirdparties;
  the day documents (invoices, orders) do, the delete handler grows the
  refusal — recorded, not accidental. Also out of scope for now: CSV
  import/export, contact persons, list sorting options, VAT format
  validation.
- The per-company uniqueness of thirdparty codes rides the server's
  case-insensitive collation (stock MariaDB utf8mb4 *_ci) — the same
  assumption `uniq_core_company_code` already makes.
- The dark theme ships as tokens (`[data-theme="dark"]`, unvalidated per the
  reference) with no toggle — a future user setting stamps the attribute.
- Assets have no cache-busting and no configurable base path: `asset()` is
  the single seam for both when they matter.
- The shell skips what has no backend: no search box, no notifications, no
  sidebar collapse (needs JS), no menu sections (needs a grouping concept the
  menu lacks), no active-item highlight (needs the current route pre-router).
- The remaining component families — core (buttons, badges), forms, data
  (StatusPill, DataTable, Pagination), navigation — arrive as their consumers
  do; today's `badges.css` is a provisional dress, not the data family.
- A 404 unwinds before authentication primes the holders, so a signed-in
  user's 404 renders the anonymous face — the same asymmetry the menu always
  had on those pages.

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
