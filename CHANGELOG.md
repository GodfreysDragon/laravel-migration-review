# Changelog

All notable changes to this project are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/) and the project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.1.1] - 2026-10-25

### Fixed
- SQL previews built from database reads (such as SQLite table rebuilds for `->change()`, whose column list is empty in pretend mode) are now marked approximate, in the report notes and in what the AI review sees, so the AI no longer reports problems that exist only in the preview.
- The AI review now gets row counts for tables a migration touches only through `DB::table()` writes.

## [0.1.0] - 2026-10-25

### Added
- Support for Laravel 12 and 13 on PHP 8.2+.
- `migrate:review` Artisan command reviewing pending migrations, explicit paths, or `--all`.
- AST-based static analysis with nine rules: `drop-table`, `drop-column`, `rename`, `column-change`, `not-null-without-default`, `index-on-existing-table`, `data-manipulation`, `raw-sql`, `missing-down`.
- Severity escalation based on table row counts read from MySQL, PostgreSQL, SQL Server and SQLite statistics.
- SQL preview via Laravel's pretend mode.
- Optional AI review with Claude (structured outputs, server-side fallbacks, content-hash caching).
- Output formats: text, JSON, Markdown, GitHub Actions annotations. `--fail-on` exit codes for CI.
- Inline `// migration-review-ignore` comments (optionally followed by a reason), `disabled_rules`, and custom rules via config.
- Tables created earlier in the same run are treated as new, so follow-up migrations on them are not flagged on a fresh database.
- CI across PHP 8.2–8.4 × Laravel 12/13, driver tests against real MySQL and PostgreSQL, and PHPStan (Larastan) level 8.
