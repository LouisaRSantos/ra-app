# CLAUDE.md: ra-app guide

Read this file first, every session, before doing anything else.

## Working rules (must follow)
1. **Read this file before any work.**
2. **Update this file whenever you add or change a module, part or fix** (see "Change log" and the relevant section). Do it in the same piece of work, not later.
3. **Never commit, push or branch.** The user commits. Stage nothing either. Show what changed instead.
4. **Never write secrets** (DB password, routine token, API keys) into code, docs, logs or chat. Do not print `.env` values; mask or avoid them.
5. **Never run destructive DB commands** (`migrate:fresh`, `db:wipe`, `migrate:rollback` beyond this app's own tables, `DROP`) against the shared database. See "Database".
6. Confirm with the user before: installing system software, adding a composer/npm dependency, changing `.env`, or running migrations on the shared DB. Tell the user about any config change.
7. Keep changes small: reuse what exists, add the minimum that works, leave one runnable check (test) for non-trivial logic.

## Project
Remittance advice extraction admin app (Laravel 12, PHP 8.2+, Blade + jQuery + Tailwind, Vite).
Users upload payment document images; fields are extracted by an AI engine; a person reviews, corrects and confirms.

Two extraction engines, kept side by side:
| Engine | Entry | How |
| --- | --- | --- |
| **Groq** (original) | `/extractions` | `ExtractRemittanceData` job calls the Groq vision API synchronously with base64 images |
| **Claude routine** (new) | `/claude-extractions` | `DispatchClaudeExtraction` fires a Claude Code routine; the routine fetches signed image URLs and POSTs the result to a public callback |

Both write into `App\Support\ExtractionStore` (cache, 1h TTL, keyed by uuid) and share the review screens (`/extractions/{uuid}`, status, update). This is **temporary**; Phase 3 moves it to the database.

## Claude routine flow
Upload → `ClaudeExtractionController@store` (status `pending`) → job `DispatchClaudeExtraction` builds 30-min signed image URLs + one-time token, calls `RoutineClient::fire` (status `processing`) → routine downloads images, extracts, POSTs JSON to `claude.callback` with `Authorization: Bearer <token>` → callback checks status (`409` on replay), token (`403`), validates payload (`422` + failed), saves, status `completed` → user reviews and confirms.
- `ExpireClaudeExtraction` (delayed 10 min) marks runs still `processing` as `failed`.
- `429` from the routines API: job is released for `Retry-After`. Other fire errors are final (no auto re-fire; the API has no idempotency key).
- Logs: `Claude routine fired` and `Claude routine completed {seconds, images, session_url}` in `storage/logs/laravel.log`.
- The callback (`POST /claude-extractions/{uuid}/result`) and signed image route are **public by design** (token / signature protected, CSRF-exempt, throttled). Never put them behind login.
- Routine prompt and setup: `documentations/claude-routine-setup.md`. Routine API limits: 30 fires/hour/routine, 100/hour/account; runs draw from the Claude subscription usage.
- Routine environment `RA_TEST_ENV` allows `*.trycloudflare.com`.

## Local run (testing the Claude flow)
The routine runs in Anthropic's cloud, so the app must be public:
- `php artisan serve --host=127.0.0.1 --port=8000`
- `%USERPROFILE%\cloudflared\cloudflared.exe tunnel --url http://127.0.0.1:8000` (free quick tunnel; URL changes on restart)
- Set `APP_URL` in `.env` to the tunnel URL, then `php artisan config:clear`
- `php artisan queue:listen --tries=1` (queue is the `database` driver)
- `bootstrap/app.php` trusts proxies (`trustProxies(at: '*')`) so https URLs and signed URLs match behind the tunnel. Tighten when deployed.

## Database (shared, careful)
- MySQL/MariaDB, **shared with unrelated existing tables**: `api_logs, api_tokens, bank_customer, crs_ra_masterlist_dbase, deductions, delcon_si, ra_logs, remittance, sales_tracking_list, sku, test_table`. **Do not read, alter or drop these** unless the user explicitly asks.
- Every table this app creates uses `DB_PREFIX=ra_` (config/database.php). Laravel tables are prefixed too (`ra_users`, `ra_cache`, `ra_jobs`...).
- Collision watch: `ra_logs` already exists, so never create a table named `logs`. Use `audit_trails` for auditing.
- Tests run on in-memory SQLite (`phpunit.xml`), never on the shared DB.
- Money columns are `decimal(14,2)`, never float.

## Roles and access (implemented in Phase 1)
| Role | Sees | Can do |
| --- | --- | --- |
| Admin | everything | all, including users and roles |
| Account Specialist (AS) | **own** transactions and files only (never another AS's, public or not) | upload, review, confirm, mark own files public/private |
| User | **public files only** (any AS) | read-only; no transactions or extracted data |
- Accounts are created by an admin only: **no signup**. Admin sets a temporary password; user must change it at first login (`must_change_password`). Deactivate via `is_active`, do not delete. No self-service reset for now (admin resets). Email invite is a possible later change.
- Package: `spatie/laravel-permission`. Roles and permission names live in `config/ra.php`; `AccessSeeder` is idempotent (re-run `php artisan db:seed` after editing it). Non-Admin roles are only synced when the role is first created, so changes made in the Roles screen are not overwritten by a re-seed. Admin always holds every permission and cannot be edited.
- Permissions: `users.manage`, `roles.manage`, `remittances.upload`, `remittances.view-own`, `remittances.view-all`, `remittances.confirm`, `files.set-visibility`, `files.view-public` (User role only; the AS does not hold it, so an AS cannot see other specialists' public files).
- First admin: set `ADMIN_NAME`, `ADMIN_EMAIL`, `ADMIN_PASSWORD` in `.env`, then `php artisan db:seed`. Created with `must_change_password = true`.
- Guards: login is throttled (6/min), a disabled account fails like a wrong password, sessions are deleted (database driver) on deactivate and password reset, the last active admin cannot be removed/disabled, you cannot demote yourself.
- Record scope today: `ExtractionStore::getForUser()` returns 404 unless the entry's `user_id` is the caller or the caller has `remittances.view-all`. Every extraction route uses it.
- Visibility is per file (`is_public`, default private) once the files table exists (Phase 2). Enforce scope at the query/policy level, not only in the UI.

## Endpoints (keep this list accurate; anything not here must require login)
| Public | Why |
| --- | --- |
| `GET/POST /login` | sign in (POST throttled) |
| `POST /claude-extractions/{uuid}/result` | routine callback: one-time bearer token, status gate, validated payload, throttled, CSRF-exempt |
| `GET /claude-extractions/{uuid}/image/{index}` | `signed` middleware, 30-min expiring URL |
| `GET /up` | framework health check, returns no data |
Everything else is behind `auth` + `account.ok` (active account, password not pending) + a `permission:` middleware. Admin screens: `/admin/users*` (`users.manage`), `/admin/roles*` (`roles.manage`).

## Frontend standards
- jQuery + AJAX only. CSRF header is set globally in `resources/js/app.js`; 401/419 redirect to login, 423 to the password page.
- Page shell renders first, data arrives async as JSON (`/admin/users/data` is paginated, search is prefix-based so indexes are used). Lists load more on scroll (IntersectionObserver) or button.
- Page scripts are lazy chunks: `resources/js/pages/<name>.js`, selected by `@section('page', '<name>')`. Add a page = add a file + the section; no registration.
- Images use `loading="lazy" decoding="async"`.
- Look: flat colour tokens in `resources/css/app.css` (no gradients, 1px borders, system font, one accent). Reuse the component classes (`.panel`, `.table`, `.btn`, `.badge`, `.modal`, `.field`) before adding new ones. Run `npm run build` after changing CSS/JS or Blade classes.
- Modals use native `<dialog>`; toasts via `window.toast(message, isError)`.


## Schema (Phase 2, built and migrated; all `ra_` prefixed, normalized)
`users 1─< remittances 1─< remittance_files`, `remittances 1─< remittance_invoices >─1 sales_invoices`, `remittances 1─< extraction_runs`.
Models in `app/Models`, enums in `app/Enums` (`ExtractionStatus`, `ExtractionEngine`), factories for every table. Tests: `tests/Feature/SchemaTest.php`.

| Table | Columns | Indexes / constraints |
| --- | --- | --- |
| `remittances` | uuid (public id, URLs use it), user_id, status, payment_reference_number, cr_number, deposit_date, total_amount `decimal(14,2)`, total_cwt_amount, low_confidence_fields (json), confirmed_by, confirmed_at | unique uuid; (user_id, created_at); (user_id, status); status; created_at; payment_reference_number; cr_number; deposit_date. `user_id` RESTRICT (deactivate users, never delete), `confirmed_by` SET NULL |
| `remittance_files` | remittance_id, position, path, original_name, mime_type, size, is_public (default false) | unique (remittance_id, position); (is_public, created_at). Cascade with the remittance |
| `sales_invoices` | si_number | unique si_number; one row per invoice, reused across remittances |
| `remittance_invoices` | remittance_id, sales_invoice_id, amount, cwt_amount (default 0), low_confidence | unique (remittance_id, sales_invoice_id); FK index on sales_invoice_id. Cascade with the remittance, RESTRICT on the invoice |
| `extraction_runs` | remittance_id, engine, status, callback_token_hash (sha256, never the token), session_url, fired_at, completed_at, duration_seconds, error, raw_result (json) | (remittance_id, created_at); (status, fired_at) for the sweeper; (engine, status). One row per attempt, so retries and Groq-vs-Claude never overwrite |

Access scopes (use these, never ad hoc filters):
- `Remittance::visibleTo($user)`: all with `remittances.view-all`, otherwise own only.
- `RemittanceFile::visibleTo($user)`: public files when the user holds `files.view-public`, plus files of remittances they can see.
- `ExtractionRun::tokenMatches($token)` compares against the stored hash.
- Not wired yet: the extraction flow still runs on the cache (`ExtractionStore`). Phase 3 switches both engines to these tables.

## Phases and status
| # | Phase | Status |
| --- | --- | --- |
| spike | Claude routine engine (separate module) | done, working end to end |
| 0 | Foundation: DB prefix, test isolation, migrations on shared DB | done |
| 1 | Auth (login only), roles, permissions, admin screens for users/roles | done |
| 2 | Normalized transaction schema | done |
| 3 | Move both engines from cache to the DB, ownership, policies | **next** |
| 4 | Admin UI: transactions list/detail, public files screen | pending |
| 5 | Hardening: audit trail, export, sweeper, deploy to a real domain | pending |
Each phase has a gate; do not start the next until the gate passes.

## Extraction field rules (prompt knowledge)
Fields: payment_reference_number, cr_number, total_amount, total_cwt_amount, deposit_date, low_confidence_fields, invoices[{si_number, amount, cwt_amount}].
- cr_number only from a COLLECTION RECEIPT's "Invoice No." field; never an invoice.
- total_cwt_amount from BIR Form 2307 "Tax Withheld For the Quarter".
- SI numbers: any "SI"+digits; reconstruct full code when only trailing digits are listed.
- deposit_date has no reliable label (usually just "Date"): priority check/deposit slip, then payment-details doc, then collection receipt; ignore invoice/due/2307 dates; assume MM/DD/YYYY; flag low confidence if ambiguous. The routine prompt (in claude.ai, not in this repo) must carry this rule.

## Conventions
- Tests that touch the DB use `RefreshDatabase` (SQLite in memory) and `$this->seed(AccessSeeder::class)`. `UserFactory` sets `is_active`/`must_change_password`.
- Spatie roles/permissions need no manual cache handling; the seeder clears the registry cache.
- Match surrounding code style; short comments only for non-obvious "why".
- Tests: `php artisan test`. Claude flow tests: `tests/Feature/ClaudeExtractionTest.php` (HTTP and queue faked).
- `.env.example` documents every new env key.

## Open items
- Rotate the DB password (it was exposed in a chat transcript on 2026-10-14).
- Routine response field names and `anthropic-version` header verified against docs and working.
- Token/cost per document is not measurable from the routines API; compare with the Messages API in a later evaluation if needed.
- Tunnel is test-only; production needs a stable HTTPS domain and a supervised queue worker.

## Change log
- 2026-10-14: Claude routine engine added (`app/Claude/*`, `DispatchClaudeExtraction`, `ExpireClaudeExtraction`, `ClaudeExtractionController`, routes `/claude-extractions`, tests, `documentations/claude-routine-setup.md`).
- 2026-10-14: trustProxies enabled for tunnel (`bootstrap/app.php`).
- 2026-10-14: completion timing log added to the callback.
- 2026-10-14: `DB_PREFIX=ra_`, SQLite test isolation, base Laravel tables migrated on the shared DB.
- 2026-10-14: CLAUDE.md created.
- 2026-10-14: Phase 1 done. Added spatie/laravel-permission, `users.is_active`/`must_change_password` (+ name/is_active indexes), `config/ra.php`, `AccessSeeder`, login/logout/change-password, `EnsureAccountIsUsable` (`account.ok`), admin Users and Roles screens (AJAX, lazy chunks), per-record ownership on all extraction routes, new flat UI shell, tests in `tests/Feature/AccessTest.php`. Extraction routes now require login; callback and signed image route remain public. Migrations (`ra_` tables only) and seeding were run on the shared DB.
- 2026-10-14: Phase 2 done. Migrations for `remittances`, `remittance_files`, `sales_invoices`, `remittance_invoices`, `extraction_runs` (run on the shared DB, `ra_` tables only), models, enums, factories, `visibleTo` scopes, `tests/Feature/SchemaTest.php`. Removed `files.view-public` from the Account Specialist role (config/ra.php and the DB role) so an AS only ever sees their own files.
