# Project Summary: Laravel Migration Review

_Last updated: 2026-10-25 (v0.1.0)_

## What it is

An open-source Composer package that adds `php artisan migrate:review` to any Laravel 12/13 app. It checks migrations for production risks before they run: data loss, NOT NULL columns that fail on existing rows, index builds that lock large tables, renames that break the release still serving traffic mid-deploy, and backfills hidden in `up()`. It is designed for CI, with GitHub inline annotations, Markdown PR comments, JSON output and `--fail-on` exit codes.

It has two layers:
1. **A static rule engine.** No API key and no database writes. Migrations are parsed into an AST and never executed for analysis.
2. **An optional Claude review.** It sees the source, the SQL preview, the driver, real table sizes and the rule findings, and reports only what the rules missed.

**Stack:** PHP 8.2+ · Laravel 12/13 · nikic/php-parser 5 · Anthropic PHP SDK (Claude Opus 5.5, structured outputs, server-side fallbacks) · PHPUnit 12 + Orchestra Testbench · PHPStan/Larastan · Pint · GitHub Actions

## What was built

### Analysis pipeline
- **AST parser** (`src/Support/MigrationParser.php`). Extracts `Schema::` operations (including `Schema::connection()->table()` and arrow-function closures), flattens fluent Blueprint chains (`$table->string('x')->nullable()->change()`), and records raw SQL, query-builder writes and Eloquent model usage. Syntax errors become a finding instead of a crash.
- **Nine rules** (`src/Analysis/Rules`): drop-table, drop-column, rename, column-change, not-null-without-default, index-on-existing-table, data-manipulation, raw-sql, missing-down. Each gives a severity, an explanation and a concrete fix.
- **Context-aware severity:**
  - Row counts come from database statistics (MySQL `information_schema`, PostgreSQL `pg_class.reltuples`, SQL Server `sys.partitions`, SQLite `COUNT(*)`). Lock-sensitive findings move up one level on large tables.
  - Advice is driver-specific: `CREATE INDEX CONCURRENTLY` on PostgreSQL, `ALGORITHM=INPLACE` or gh-ost on MySQL.
  - Rules know about Laravel quirks, for example `->change()` dropping modifiers you don't restate since Laravel 11.
- **No false positives on new tables:** anything done to a table created in the same migration, or by an earlier migration in the same run that has not been applied yet, is ignored.
- **SQL preview** through Laravel's `pretend` mode, with schema-introspection reads filtered out.
- **Suppression:** `// migration-review-ignore[: rule-id]` comments, `disabled_rules`, and custom rules via config.

### AI review (`src/Ai`)
- `ClaudeReviewer`: Anthropic SDK beta Messages call.
  - Uses a strict JSON-schema structured output, so replies always parse.
  - Server-side fallbacks (`fallbacks: "default"`) are on, effort is set explicitly, and the prompt is hardened against injection from migration source.
- `CachingReviewer`: caches by a hash of everything the model sees (source, SQL, sizes, findings, model, prompt version), so unchanged migrations cost nothing in CI.
- Graceful degradation: auth errors, rate limits, refusals and bad JSON become report notes. Static findings and the exit code are unaffected.

### Command and output
- Reviews pending migrations by default, or explicit files/directories, or `--all`. Paths resolve from the working directory.
- Options: `--format` (text, json, markdown, github), `--output`, `--fail-on`, `--no-ai`, `--no-sql`, `--no-stats`, `--show-sql`, `--database`.

## Verification status

| Area | Status |
| --- | --- |
| Unit and feature tests | ✅ 59 tests, all passing on SQLite |
| MySQL and PostgreSQL | ✅ Driver tests (row-count queries, driver-specific findings, SQL preview, batch-created tables) pass against MySQL 8.4 and PostgreSQL 16; CI runs them in service containers |
| Static analysis | ✅ PHPStan level 8 (Larastan), no errors |
| Laravel 12 compatibility | ✅ Full suite passes against Laravel 12.69 / Testbench 10 (scratch install) |
| Laravel 11 | ❌ Dropped: every 11.x release is blocked by Packagist security advisories, so Composer won't install it by default |
| Claude request/response handling | ✅ Tested through the real SDK with a stubbed HTTP transport: request body, beta header, fallbacks, schema, refusal, invalid JSON, 401 |
| Live Claude call | ⚠️ Not run: no `ANTHROPIC_API_KEY` on this machine |
| Real-world run | ✅ Ran read-only against `saas-boilerplate` and `rag-doc-qa` migrations: 16 migrations, 1 genuine finding (FK added to existing `users`). The raw-sql rule was retuned after it over-flagged pgvector setup on a newly created table |
| Code style | ✅ Pint (Laravel preset) passes |
| CI workflow | ✅ Green on GitHub Actions for PHP 8.2–8.4 × Laravel 12/13 + Pint. The MySQL/PostgreSQL and PHPStan jobs were added later and first run when this branch is opened as a PR |
| Published | ✅ On GitHub as `GodfreysDragon/laravel-migration-review`. Deliberately not on Packagist; installs via a Composer `vcs` repository |

## Remaining work

1. Add `ANTHROPIC_API_KEY` locally and run `php artisan migrate:review --all` on a real app to capture genuine AI output for the README.
2. Optionally, a small demo repo with a PR that trips the rules, for a screenshot of the inline annotations.
