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

## Roles and access (target design, Phase 1+)
| Role | Sees | Can do |
| --- | --- | --- |
| Admin | everything | all, including users and roles |
| Account Specialist (AS) | **own** transactions and files only | upload, review, confirm, mark own files public/private |
| User | **public files only** (any AS) | read-only; no transactions or extracted data |
- Accounts are created by an admin only: **no signup**. Admin sets a temporary password; user must change it at first login (`must_change_password`). Deactivate via `is_active`, do not delete. No self-service reset for now (admin resets). Email invite is a possible later change.
- Package: `spatie/laravel-permission` (not yet installed).
- Visibility is per file (`is_public`, default private). Enforce scope at the query/policy level, not only in the UI.

## Target schema (Phase 2, all `ra_` prefixed, normalized)
`users 1─< remittances 1─< remittance_files`, `remittances 1─< remittance_invoices >─1 sales_invoices`, `remittances 1─< extraction_runs`.
- `remittances`: user_id, status, payment_reference_number, cr_number, deposit_date, total_amount, total_cwt_amount, confirmed_by, confirmed_at
- `remittance_files`: remittance_id, path, mime, original_name, position, is_public
- `sales_invoices`: si_number (unique)
- `remittance_invoices` (pivot): remittance_id, sales_invoice_id, amount, cwt_amount
- `extraction_runs`: remittance_id, engine (groq|claude), status, session_url, seconds, error, raw_result (json), timestamps

## Phases and status
| # | Phase | Status |
| --- | --- | --- |
| spike | Claude routine engine (separate module) | done, working end to end |
| 0 | Foundation: DB prefix, test isolation, migrations on shared DB | done |
| 1 | Auth (login only), roles, permissions, admin screens for users/roles | **next** |
| 2 | Normalized transaction schema | pending |
| 3 | Move both engines from cache to the DB, ownership, policies | pending |
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
