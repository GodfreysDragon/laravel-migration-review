# Changelog

All notable changes to this project are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/) and the project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Changed
- Published on Packagist: install with `composer require --dev godfreysdragon/laravel-migration-review`. The `vcs` repository entry is no longer needed and can be removed from your `composer.json`.

## [0.2.0] - 2026-10-26

### Changed
- **Breaking:** the root namespace is now `GodfreysDragon\MigrationReview` (was `MigrationReview`). If you published the config, change `use MigrationReview\Analysis\Rules;` at the top of `config/migration-review.php` to `use GodfreysDragon\MigrationReview\Analysis\Rules;`. Custom rules need the same change in their `use` statements.
- The AI review gets the SQL preview with bound values left as `?`. Values computed at runtime, such as `config('services.x.secret')` inserted by a migration, previously went to the API. The local report still shows them. Cached AI reviews are refreshed once because the prompt changed.
- `ext-mbstring` is now an explicit requirement. Laravel already needs it.

### Added
- `MIGRATION_REVIEW_SQL_PREVIEW` environment variable to turn the SQL preview off.
- A Security section in the README and a `SECURITY.md` with private vulnerability reporting.

### Fixed
- The docs no longer claim migrations are never executed. The SQL preview calls `up()` in pretend mode, which captures queries on the migration's connection but runs any other code in `up()`; the README now says so and explains when to use `--no-sql`.
- Markdown reports escape text from migrations, errors and the AI, so a PR comment can't gain links, images, HTML or `@mentions` from them.
- Control characters, including terminal escape sequences, and invalid UTF-8 are removed from text, Markdown and GitHub output.
- Migration source can no longer close a section of the AI prompt early (for example with `</migration>`), and the file path is escaped in the prompt.
- The SQL preview showed a wrong statement when a bound value contained `?`. Values are now substituted by Laravel's own grammar.

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
