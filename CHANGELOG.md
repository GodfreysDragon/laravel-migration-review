# Changelog

All notable changes to this project are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/) and the project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Changed
- Package renamed to `godfreysdragon/laravel-migration-review`.

### Fixed
- Tables created by an earlier migration in the same run are treated as new, so follow-up migrations on them are no longer flagged as risky on a fresh database.
- `// migration-review-ignore: <rule-id>` comments can now be followed by a free-form reason.
- The Markdown report keeps notes (for example a failed AI review) for migrations with no findings.

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
