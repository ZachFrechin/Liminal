# Liminal

An open-source modular ERP in PHP 8.4. Hand-built micro-kernel, PSR bricks, no framework.

## The three-primitive model

Everything in Liminal is one of these three things, and nothing else:

| Primitive | Role | Examples |
|---|---|---|
| **Lib** | A technical capability. No routes of its own beyond diagnostics, no business data. Ships with the core. | `system`, `database`, `security`, `rendering`, `api` |
| **Module** | A business vertical: pages, logic, data. Consumes the libs. Installable, activatable per company. | `authentication`, `thirdparty` |
| **Registry** | The only coupling surface between the two and the kernel. | `RouteRegistry`, `EntityRegistry`, … |

The rule that holds it all together: **a module never touches the core; it only
fills registries.** That is what keeps the system enumerable — and what will
make the module builder possible.

### The boot cycle

```
Kernel::boot()
  ├─ loads the configuration
  ├─ instantiates the contributors            ← plain `new`: they are manifests
  ├─ collects their container definitions     ← DefinitionProvider (optional)
  ├─ builds the PSR-11 container
  ├─ each Contributor fills the registries    ← libs first, in app.libs order
  └─ RegistryCollection::freeze()             ← the system's shape is now fixed
```

After `freeze()`, every contribution raises a `FrozenRegistryException` —
including registering a whole new registry. No request-scoped code can change
the system's shape: what is enumerable at boot stays enumerable.

Duplicate contributions are refused *during* boot (`DuplicateContributionException`):
a colliding route, permission code, setting key or migration namespace fails
loudly while the offending contributor is still on the stack. Overriding will be
an explicit API when modules arrive — never a silent last-wins.

`Contributor` is the single extension interface, implemented identically by
libs and modules:

```php
interface Contributor
{
    public function contribute(RegistryCollection $registries): void;
}
```

A lib that needs services in the container also implements the optional
`DefinitionProvider`: its `definitions()` run before the (immutable once built)
container exists, must stay lazy, and may not redefine the kernel-structural
ids — the registries are reserved; `LoggerInterface` is fair game.

The HTTP pipeline itself is a registry: middleware is contributed to the
`MiddlewareRegistry` with a priority (lower = outer) between the kernel's
anchors — error handler, router, dispatcher. Anything sorted outside the error
handler or behind the dispatcher fails the boot by name.

A business module implements `Module` (a `Contributor` plus its identity:
name, version, migration namespace) and is declared in `app.modules`. Its
*shape* — routes, entities, migrations — always boots: declared means "part
of this installation". Its *state* lives in the database: `module:install`
runs the module's own migrations and records it, `module:enable` /
`module:disable` toggle it per company, `module:list` shows everything.
Whether a company may actually reach a module's pages is enforced per request
from phase 3 on — boot never consults installed/enabled state, by design.

## Hooks and triggers

A strict distinction, never to be allowed to drift (implementation in phase 2):

| | **Hook** | **Trigger** |
|---|---|---|
| Moment | synchronous, in the flow | afterwards, post commit |
| May modify? | yes — the value travels listener to listener | no |
| Exceptions | propagate (it is business logic) | caught and logged |
| Naming | `invoice.total.compute` | `INVOICE_VALIDATED` |

## Getting started

```bash
composer install
docker compose up -d db                       # LIMINAL_DB_PORT overrides the host port
export LIMINAL_DSN='mysql://liminal:liminal@127.0.0.1:3306/liminal_test'
php bin/liminal install                       # migrations + first company + enables declared modules
php bin/liminal authentication:user:create you@example.com   # prompts the password, mints the admin role
php -S localhost:8080 -t public               # then sign in at localhost:8080/login
php bin/liminal doctor
php bin/liminal migrate:status
```

Two commands take a virgin database to a signed-in administrator: `install`
seeds the first company and enables every declared module for it;
`user:create` prompts for a password (hidden, twice — there is no `--password`
option on purpose: argv lands in shell history and `ps`) and creates the
`admin` role holding every declared permission.

### Checks

```bash
composer lint     # PHP-CS-Fixer, PER-CS 2.0
composer stan     # PHPStan level max, no baseline
composer test     # PHPUnit
composer check    # all three
```

Integration tests need a real MariaDB server — not SQLite, because the
behaviour under test (SQL filters, migrations, real DDL, NULL semantics in
unique indexes) is exactly what SQLite would fake.

```bash
docker compose up -d db         # LIMINAL_DB_PORT overrides the host port
export LIMINAL_TEST_DSN='mysql://liminal:liminal@127.0.0.1:3306/liminal_test'
composer test:integration
```

Without a reachable `LIMINAL_TEST_DSN`, the integration suite *skips* instead
of failing. Environment variables are documented in `.env.example`.

## Layout

```
bin/liminal          CLI
config/              app.php, database.php, security.php
docs/CONVENTIONS.md  THE coding standard — read it before contributing
libs/                technical capabilities (System, Database, Security, Rendering, Module)
modules/             business verticals (Authentication, …) — consume libs, fill registries
public/index.php     single web entry point
src/                 THE KERNEL — neither lib nor module
  Config/ Console/ Container/ Exception/ Http/ Registry/ Support/
tests/{Unit,Integration}
```

## Status

| Phase | Content | State |
|---|---|---|
| 0 | Kernel, registries, PSR-15 pipeline, CLI, CI | ✅ |
| 1 | `lib/database`: Doctrine, multi-company scoping, per-module migrations, DI wiring, doctor | ✅ |
| 2a | Kernel plumbing: contributable middleware pipeline, named-route URLs, `migrate`/`install`, settings values | ✅ |
| 2b | `lib/module`: Module contract, `app.modules`, install/enable lifecycle per company | ✅ |
| 3 | `lib/security`: database sessions, deny-by-default routes, CSRF, per-request company scope, module gating | ✅ |
| 4 | `lib/rendering`: Twig, contributable templates, view helpers, menu, translations, HTML error pages | ✅ |
| 5a | `module/authentication`: users, per-company RBAC, sign-in pages, throttle + audit, bootstrap commands | ✅ |
| 5b | `module/companies`: company CRUD, role screens, user administration | upcoming |
| 6 → 8 | `lib/api`, builder, business modules | upcoming |

## Security

Sessions live in the database (`core_session`), keyed by the SHA-256 of the
cookie value — a leaked dump contains nothing a browser could replay. Both
OWASP timeouts ride one indexed column: idle, and an absolute cap counted from
authentication. Session ids are only ever server-generated, so fixation by
cookie injection is structurally impossible, and every privilege change
regenerates the id.

The whole design fails closed. The error handler is the outermost middleware,
so an exception unwinds *through* the session middleware — a 404 from a
crawler, a 401 spray, a CSRF refusal all persist nothing. Sessions only touch
the database when the client actually engaged. The corollary matters for
anything built on top: **a failed login must return a response, never throw**,
or the attempt's state is lost.

Routes are protected unless declared `public: true`. Requests then pass, in
order: session (−900), authentication (100), CSRF (200), company switch (300),
module gate (400).

| Layer | Refusal |
|---|---|
| Unauthenticated on a protected route | 401 JSON; a browser is redirected to the login page carrying `?redirect=` |
| Unsafe method without a valid synchronizer token | 403 |
| Route of a module disabled for this company | 404, identical to a nonexistent path (public routes bypass the gate — see below) |
| Permission the resolver denies | `Gate::allows()` false for content, `RequestGate::authorize()` throws 403 for pages |

The security lib owns the contracts — `UserProvider`, `PermissionResolver`,
`LoginThrottle`, `AuthEventLog` — and ships inert defaults (no users, no
grants, no limits, no log). The authentication module replaces all four
through the same last-wins definition layering any module gets; nothing about
it is a special case. Login throttling is checked *inside* the
`Authenticator`, before any bcrypt runs, per identifier **and** per address —
and success clears only the identifier counter, so a same-NAT attacker cannot
launder an address lockout by logging into their own account.

## Rendering

Pages are Twig, and templates are contributions like everything else: a lib or
module registers a namespace in the `TemplateRegistry` (`@liminal`,
`@<module>`), and because Twig resolves a namespace's paths first-hit-wins, the
registry serves them latest-contribution-first — so a module can shadow a lib's
template. Translations work the same way through the `TranslationRegistry`:
plain PHP catalogues merged in contribution order, last one winning.

`strict_variables` is on everywhere: a missing variable is a wiring bug, not a
blank cell. Nothing uses `|raw` — autoescaping is the XSS story.

The view helpers are `url()`, `csrf_token()`, `csrf_field()`, `flash()`,
`current_user()`, `menu()` and the `trans` filter. Two of them fail in opposite
directions on purpose: `flash()` tolerates a missing session (an absent flash
is the normal case — content), while `csrf_token()` refuses one (a form without
a real token means every later POST fails 403 — wiring). A missing translation
key renders as the key itself; a malformed catalogue file fails loud.

The menu finally consumes the `MenuRegistry`: anonymous requests get an empty
menu before any database work (public pages must render with no DSN in reach),
then items of modules disabled for the current company disappear, then
permission-gated items the `Gate` denies. A hidden parent takes its whole
subtree with it.

Browsers get HTML refusals: a 404 renders an error page, a 401 redirects to the
configured login route carrying the intended path as `?redirect=` — never in
the session, since the unwind path must not mint one row per probe. JSON
clients keep the exact JSON contract they had before rendering existed.

## The authentication module

The first business module, and the proof the primitives compose: it touches no
core code, only fills registries and overrides lib contracts through ordinary
definition layering.

**Users** sign in with their email (the sole identifier — one uniqueness, one
enumeration surface, and the future reset path needs it anyway), normalised in
PHP so uniqueness never depends on the server's collation. `is_active` is the
whole deactivation feature: filtered on login *and* on session hydration, so a
deactivated user's live session ends at their next request.

**RBAC is granted per company**: roles are global (`core_role`, with their
permission codes), what varies is the grant — `core_user_company_role` binds
user × company × role. A user with no grant anywhere has no accessible
companies and cannot sign in at all. Permission reads are memoised per (user,
company) and invalidated by the same `CompanyContext::onSwitch()` hook that
clears the EntityManager, so a menu of N gated items costs one grants query
per request.

**None of its tables is company-scoped.** Login is pre-company (the filter
would fence an anonymous request into the bootstrap company — a user of only
company 2 could never sign in), and grants are cross-company administration by
nature. The request-path security reads use plain DBAL, never the ORM: the
company switch clears the EntityManager once per request *after*
authentication runs, so any entity hydrated at auth time would be detached one
middleware later, by construction.

**Public routes** — `GET/POST /login`, `POST /logout` — bypass the module
gate: pre-authentication is pre-company, so "is this module enabled for your
company" is a question without a subject. That is what makes sign-in reachable
before anything is enabled, and what keeps `module:disable authentication`
from becoming a permanent lockout. Signing *out* must never depend on being
signed in, so `/logout` is public too — still CSRF-protected, since token
checks are method-based, not route-based.

**Every login attempt leaves exactly one audit row** (`core_auth_event`:
granted, refused, throttled, logout), append-only, with `SET NULL` on user
deletion — deleting an account must not erase the record of what it did.
Failed attempts flash and redirect, never throw: the error path persists no
session state, which is precisely where a thrown failure would lose the flash.

## Multi-company

An entity implementing `CompanyScoped` is automatically fenced per company:
`CompanyScopeFilter` appends `company_id IN (...)` to every query, and
`prePersist` stamps new rows with the current company.

**The SQL filter is not a security boundary.** Doctrine only applies it when
generating SQL: `find()` short-circuits on the identity map before the
persister — and therefore before the filter — runs. Complementary mechanisms
close the gaps, and none of them is sufficient alone:

- `CompanyContext::switchTo()` clears the EntityManager, so nothing hydrated
  under the previous scope survives into the next one;
- a `postLoad` guard refuses any foreign row even with the filter disabled;
- an `onFlush` gate makes `company_id` **write-once**: reassigning a managed
  entity to another company — even an accessible one — is refused before a
  single statement executes (`CompanyReassignmentException`), because a silent
  flip is indistinguishable from an exfiltration. Moving rows between
  companies will be an audited administrative service, not an ORM operation;
- phase 3 adds voters on top.

Switching company goes through `switchTo()` and nothing else: there is no
plain setter, precisely so the eviction cannot be forgotten. The security lib's
`CompanySwitchMiddleware` does exactly that once per request, from the
authenticated user's stored preference — validated against their accessible
set, so a forged session value falls back instead of widening the scope.
Anonymous requests run under `database.bootstrap_company_id` (default 1).

One rule worth stating plainly: the filter scopes reads to the **accessible
set**, not to the current company alone. An actor entitled to two companies
reads across both; the current company is what new rows are stamped with.
