# Changelog

All notable changes to this project are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/) and the project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.1.0] - 2026-10-04

### Added
- Support for Laravel 12 and 13 on PHP 8.2+.
- `migrate:review` Artisan command reviewing pending migrations, explicit paths, or `--all`.
- AST-based static analysis with nine rules: `drop-table`, `drop-column`, `rename`, `column-change`, `not-null-without-default`, `index-on-existing-table`, `data-manipulation`, `raw-sql`, `missing-down`.
- Severity escalation based on table row counts read from MySQL, PostgreSQL, SQL Server and SQLite statistics.
- SQL preview via Laravel's pretend mode.
- Optional AI review with Claude (structured outputs, server-side fallbacks, content-hash caching).
- Output formats: text, JSON, Markdown, GitHub Actions annotations. `--fail-on` exit codes for CI.
- Inline `// migration-review-ignore` comments, `disabled_rules`, and custom rules via config.
