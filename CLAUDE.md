# Liminal — assistant notes

Modular ERP core, PHP 8.4, no framework. `src/` is the kernel (config,
container, HTTP pipeline, registries), `libs/` are the technical capabilities
(System, Database, Security, Rendering, Module), `modules/` are the business
verticals (Authentication is the admin reference; Companies has NO migrations
— `migrationNamespace()` null is ordinary; Thirdparty is the BUSINESS
reference: CompanyScoped entity, ORM CRUD, repository, pagination, search).
Everything extends the system through registries filled at boot, then frozen;
libs and modules expose services through `DefinitionProvider` definitions
collected before the container is built — a module overrides a lib's contract
by ordinary last-wins layering, never by special case.

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
on the unwind path where the session is never persisted — the shell's two form
regions (`sidebar_actions`/`topbar_actions` blocks) are overridden empty there.
Flashes are catalogue keys, translated by the layout at render time, rendered
as banners in BOTH shell faces. Menu labels too.

Design system (8): reference = the claude.ai/design project; implementation =
`public/assets/` (tokens + components CSS behind `liminal.css`, vendored OFL
fonts, Lucide glyphs in `IconSet` — unknown name throws). Zero CDN at runtime.
Components consume semantic aliases only (TokenDriftTest enforces); macros only
with a real consumer (`ui.banner`, `ui.empty_state`). **Layout `{% set %}`
leaks into child blocks** — layout variables carry `_liminal_` prefixes, never
plain names (an unprefixed `companies` once 500'd the companies list). Shell
dropdowns are native `<details>`; module routes it names sit behind
`route_exists()`; switch forms hide when authentication is disabled for the
working company.

Administration (5b): CRUD failures are flash + 302 (the only sanctioned
deviation: one-time secrets render directly from the POST with no-store —
never through session or store). Self-deactivation/self-deletion refused;
self-revocation allowed (per company, recoverable). Company/role codes are
immutable after creation; the `admin` role is undeletable (screen + service).
`user.manage` in ONE company = instance-wide administration and
escalation-equivalent (grant-add can assign admin anywhere) — role.manage
protects definitions only. Password reset calls
`SessionManager::endAllFor()` — a credential change kills the other sessions.

Business modules (6): **CRUD through the ORM** (the company fence lives only
there — DBAL on a scoped table bypasses it; exact inverse of the
security/admin DBAL rule, both deliberate). Repositories narrow every read
to the CURRENT company in DQL (the filter stays on the accessible set — it
is the security boundary, phase-1 tests pin it). LIKE search: explicit
`ESCAPE '!'` + escape the bound value. Pagination: lib `Page<T>`, COUNT,
clamp page, ORDER BY with `, id` tiebreaker, PER_PAGE on the repository.
Permission split: manage presumes read (handlers authorize read first,
writes manage too); collapse rule `thirdparty.read` when the module is
named after its entity.

Invoice (9): module→module dependency sanctioned ONE direction, declared
(invoice imports thirdparty — the FK is real; reverse = the veto hook,
never an import). NO mapped associations — plain FK columns + `JOIN … WITH`
(both aliases get the filter, repository narrows both). Money = integer
cents over DECIMAL strings, per-field form caps (int64), VAT rounded per
rate group. Validation = the tree's one `wrapInTransaction` (counter upsert
+ freeze, one commit; ANY in-wrap throw closes the EM — inverse of the
bare-flush rule; émission IS validation, number from the validation day's
year). Cascade semantics MEASURED per diamond — they DISAGREE: company
delete resolves the invoice diamond but trips 1451 on the order→invoice
diamond (OrderScopeTest pins the inversion) — applicative delete,
most-referencing first, is the only path that works on both. Targeted
deletes of referenced rows always refused by RESTRICT + answered politely
by the vetoes.

Order (10): the second document — money machinery hoisted to
`libs/Database/Money` (Cents, DocumentLine 3-getter contract,
DocumentTotals, TotalsCalculator) + `libs/Database/Sequence/YearlySequence`
(atomic claim, runtime identifier guard); each module keeps its table +
format (`CMD-%d-%04d` / order_sequence). Three states draft→validated→
invoiced, `isValidated()` STRICT (false once invoiced); markInvoiced sets
status+pointer+timestamp in ONE mutation (invariant status=INVOICED ⇔
invoice_id NOT NULL). Conversion = ONE wrapInTransaction (invoice born,
lines copied, totals from the INVOICE's own hook — never copied —, pointer
set; explicit inner flushes legal, assign ids mid-wrap), demands
invoice.manage AND order.manage, definitive (unlink = recorded gap);
post-commit fires ORDER_INVOICED + cross-module INVOICE_CREATED
(deliberate, docblocked). Several responders per veto = normal (only exact
(name,listener) pair refused; flash shows reasons[0], order =
app.modules); listen() lands AFTER the commit that declares the name —
freeze validates at every boot. Four production hooks:
invoice.total.compute, order.total.compute, thirdparty.deletion.veto
(2 responders), invoice.deletion.veto.

Hooks & triggers (7): declare-then-listen (dispatcher declares the name,
consumers subscribe by service id, freeze validates targets). Hook =
sync, value travels, exceptions PROPAGATE, `module.noun.verb`. Trigger =
post-commit (a CONVENTION fire points must honor), caught-and-logged,
SCREAMING_SNAKE ≤64. Payloads `array<string, scalar|null>`, never a
secret; `fire(..., companyId:)` when the point knows better than the
working context. Trigger listeners never fire triggers. Every admin
mutation fires; the audit catch-all (`AUDIT_PRIORITY = -1000`, anchor)
writes `core_audit_event` — zero FKs, historical facts. Login stays on
AuthEventLog.

Every commit: `type(scope): imperative subject`, body explains why,
`composer check` green. Integration tests skip without a reachable DSN — run
them with the database up before claiming database-touching work done.

Two traps with teeth: the console resolves every registered command eagerly,
so command constructors must never inject Connection, EntityManagerInterface
or SettingsService — inject the deferred-connection services (MigrationRunner,
DatabaseHealth, FirstCompanySeeder) instead. And middleware priorities are
lower = outer between the anchors (-1000 error handler, 0 router, 1000
dispatcher); boot refuses anything outside them.
