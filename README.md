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
php bin/liminal install                       # migrations + first company (MAIN)
php -S localhost:8080 -t public
curl localhost:8080/                          # {"status":"ok","routes":1}
php bin/liminal doctor
php bin/liminal migrate:status
```

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
config/              app.php, database.php
docs/CONVENTIONS.md  THE coding standard — read it before contributing
libs/                technical capabilities (System, Database, …)
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
| 4 → 8 | `lib/rendering`, `lib/api`, builder, business modules | upcoming |

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
| Unauthenticated on a protected route | 401 JSON (a login redirect replaces it in phase 4) |
| Unsafe method without a valid synchronizer token | 403 |
| Route of a module disabled for this company | 404, identical to a nonexistent path |
| Permission the resolver denies | `Gate::allows()` returns false (deny-all until phase 5) |

The `UserProvider` and `PermissionResolver` defaults are deliberately inert
(no users, no grants): the phase-5 authentication module replaces them through
the same definition layering any module gets.

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
