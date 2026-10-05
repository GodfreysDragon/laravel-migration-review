# Laravel Migration Review

[![Tests](https://github.com/calmarusin/laravel-migration-review/actions/workflows/tests.yml/badge.svg)](https://github.com/calmarusin/laravel-migration-review/actions/workflows/tests.yml)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/calmarusin/laravel-migration-review.svg)](https://packagist.org/packages/calmarusin/laravel-migration-review)
[![License](https://img.shields.io/packagist/l/calmarusin/laravel-migration-review.svg)](LICENSE.md)

**Catch dangerous migrations before they reach production.**

`php artisan migrate:review` checks your pending migrations for the changes that cause outages and data loss: dropped columns, NOT NULL columns that fail on existing rows, index builds that lock big tables, renames that break the release still running mid-deploy, backfills hiding in `up()`. It runs as a fast static rule engine with no database writes and no API key. Add an Anthropic key and Claude reviews each migration too, with the SQL, your table sizes and the rule findings as context.

Example output against a PostgreSQL database where `orders` has 4.2M rows, with AI review enabled:

```text
$ php artisan migrate:review

  HIGH  2026_01_02_000000_add_status_to_orders_table
      database/migrations/2026_01_02_000000_add_status_to_orders_table.php
      AI: Safe on an empty table, but on the 4.2M-row orders table the NOT NULL column fails
          and the index build blocks writes for minutes.

       HIGH  :12 Adds NOT NULL column `status` to `orders` without a default [not-null-without-default]
        The migration fails as soon as the table has any rows, because existing rows would have
        NULL in a NOT NULL column. `orders` currently has about 4.2M rows.
        → Add it as ->nullable() (or with ->default(...)), backfill existing rows, then make it
          NOT NULL in a follow-up migration.

       HIGH  :15 Builds index on `orders` (customer_email) [index-on-existing-table]
        Building an index reads the whole table and blocks writes while it runs on most setups.
        `orders` currently has about 4.2M rows.
        → On PostgreSQL use DB::statement('CREATE INDEX CONCURRENTLY ...') in a migration with
          `public $withinTransaction = false;`.

       MED   :13 Default 'USD' silently mislabels historical orders [ai]
        Every existing order is backfilled as USD, including ones paid in other currencies.
        → Backfill currency from the payments table before relying on the column.

 Reviewed 1 migration: 2 high, 1 medium, 0 low.
```

## Why

`migrate` does exactly what you wrote, against however many rows production has. Code review often misses migration risks, because they depend on things the diff doesn't show:

- **How big the table is.** An index on a 2,000-row table is instant. On 40M rows it locks writes for minutes.
- **What the old code is doing during the deploy.** Renaming or dropping a column breaks the release that is still serving traffic.
- **Engine quirks.** PostgreSQL rejects a NOT NULL column without a default on a non-empty table. MySQL accepts it and fills existing rows with zero values.
- **Laravel quirks.** Since Laravel 11, `->change()` silently drops `nullable`, `default` and `comment` unless you restate them.

This package checks for all of these on every pull request.

## Installation

```bash
composer require --dev calmarusin/laravel-migration-review
```

Requires PHP 8.2+ and Laravel 12 or 13. The service provider is auto-discovered.

To enable the AI review, add an [Anthropic API key](https://console.anthropic.com/) to `.env`:

```dotenv
ANTHROPIC_API_KEY=sk-ant-...
```

Optionally publish the config:

```bash
php artisan vendor:publish --tag=migration-review-config
```

## Usage

```bash
php artisan migrate:review                       # pending migrations (not yet run on this database)
php artisan migrate:review database/migrations/2026_01_02_000000_add_status.php
php artisan migrate:review --all                 # every migration
php artisan migrate:review --show-sql            # include the SQL each migration would run
php artisan migrate:review --no-ai               # static rules only
php artisan migrate:review --fail-on=high        # exit 1 if anything is high severity
php artisan migrate:review --format=json         # text (default), json, markdown, github
php artisan migrate:review --format=markdown --output=review.md
```

| Option | Description |
| --- | --- |
| `paths*` | Files or directories to review. Defaults to pending migrations. |
| `--all` | Review every migration, not only pending ones. |
| `--database=` | Connection used for the SQL preview, table sizes and the pending check. |
| `--format=` | `text`, `json`, `markdown` (PR comments) or `github` (inline annotations). |
| `--output=` | Also write the report to a file. |
| `--fail-on=` | `low`, `medium`, `high` or `none` (default from config: `none`). |
| `--no-ai` / `--no-sql` / `--no-stats` | Skip the AI review, the SQL preview, or the table-size lookup. |
| `--show-sql` | Print the SQL each migration would run (text format). |

### How it works

1. **Parse.** Each migration is parsed into an AST with [nikic/php-parser](https://github.com/nikic/PHP-Parser). The migration is never executed for analysis, so the rules work without a database.
2. **Context.** Row counts for the affected tables come from database statistics: `information_schema` on MySQL, `pg_class.reltuples` on PostgreSQL, `sys.partitions` on SQL Server, `COUNT(*)` on SQLite. The SQL each migration would run comes from Laravel's `pretend` mode, so nothing is written.
3. **Rules.** Each rule reports a severity, an explanation and a concrete fix. Lock-sensitive findings move up one severity level on tables above `large_table_rows`.
4. **AI review (optional).** Claude receives the numbered source, the SQL preview, the driver, the row counts and the rule findings. It reports only what the rules missed, or where a rule got it wrong. Responses use [structured outputs](https://platform.claude.com/docs/en/build-with-claude/structured-outputs), so they are always valid JSON.

## Rules

| Rule id | Catches | Default severity |
| --- | --- | --- |
| `drop-table` | `Schema::drop()` / `dropIfExists()` on existing tables | high |
| `drop-column` | `dropColumn`, `dropSoftDeletes`, `dropTimestamps`, `dropMorphs`, `Schema::dropColumns()`… | high |
| `not-null-without-default` | Adding a NOT NULL column with no default to an existing table (including `foreignId()->constrained()`) | high (medium on MySQL) |
| `rename` | `renameColumn`, `Schema::rename` (breaks the old release mid-deploy) | medium |
| `column-change` | `->change()`: table rewrites, truncation, and modifiers dropped since Laravel 11 | medium |
| `index-on-existing-table` | Indexes, uniques and foreign keys added to existing tables, with driver-specific online alternatives | medium |
| `data-manipulation` | `DB::table()->update()/delete()/truncate()` backfills and Eloquent models used inside migrations | medium (truncate: high) |
| `raw-sql` | `DB::statement()` / `DB::unprepared()`. Destructive SQL is high; SQL that only touches tables created in the same migration is low | medium |
| `missing-down` | Missing or empty `down()` | low |

Operations on a table created **in the same migration** are not flagged, because that table has no rows yet.

### Silencing a finding

Add a comment on the line, or the line above:

```php
// migration-review-ignore: drop-column
$table->dropColumn('legacy_flag');

// migration-review-ignore        ← ignores every rule for the next line
```

Disable a rule globally with `disabled_rules` in `config/migration-review.php`.

### Custom rules

Implement `MigrationReview\Analysis\Rules\Rule` (or extend `BaseRule`) and add the class to `rules` in the config:

```php
use MigrationReview\Analysis\RuleContext;
use MigrationReview\Analysis\Rules\BaseRule;
use MigrationReview\Analysis\Severity;
use MigrationReview\Support\Parsed\ParsedMigration;

class NoEnumColumnsRule extends BaseRule
{
    public function id(): string
    {
        return 'no-enum';
    }

    public function check(ParsedMigration $migration, RuleContext $context): array
    {
        $findings = [];

        foreach ($migration->operations as $operation) {
            foreach ($operation->blueprintCalls as $call) {
                if ($call->method() === 'enum') {
                    $findings[] = $this->finding(Severity::Low, 'Enum column', 'Adding values to an enum later rewrites the table.', $call->line);
                }
            }
        }

        return $findings;
    }
}
```

## Continuous integration

### GitHub Actions: inline annotations on the PR

```yaml
# .github/workflows/migration-review.yml
name: Migration review

on:
  pull_request:
    paths: ['database/migrations/**']

jobs:
  review:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'
      - run: composer install --no-interaction --prefer-dist
      - run: cp .env.example .env && php artisan key:generate
      - name: Review migrations
        env:
          ANTHROPIC_API_KEY: ${{ secrets.ANTHROPIC_API_KEY }}
        run: php artisan migrate:review --all --format=github --fail-on=high
```

On a fresh CI database every migration counts as pending, so to review only the files a PR touches, pass them explicitly:

```yaml
      - name: Review changed migrations
        run: |
          FILES=$(git diff --name-only --diff-filter=AM origin/${{ github.base_ref }}... -- database/migrations)
          [ -z "$FILES" ] || php artisan migrate:review $FILES --format=github --fail-on=high
```

(Use `actions/checkout` with `fetch-depth: 0` so the base branch is available.)

### PR comment

`--format=markdown --output=migration-review.md` writes a table you can post with any "comment on PR" action.

### Against production statistics

Severity escalation only works when row counts are known. Run the command with `--database=` pointing at a read replica (or a production-sized staging copy) to get them. The lookups are read-only queries against catalog tables.

## The AI review

| | |
| --- | --- |
| **Model** | `claude-opus-5-5` by default (`MIGRATION_REVIEW_MODEL`), effort `medium` (`MIGRATION_REVIEW_EFFORT`). |
| **What is sent** | The migration file, its SQL preview, the database driver, row counts of affected tables, and the rule findings. No data rows, credentials or other source files. |
| **Cost** | Usually a few thousand input tokens per migration, roughly a few cents. Reviews are cached by a hash of everything sent (30 days by default), so re-running CI on an unchanged migration is free. |
| **Failure handling** | Rate limits, auth errors, refusals and malformed replies become a `note:` on the report. The static findings are still reported and the exit code is unaffected. Server-side fallbacks are enabled, so a request the model declines is retried on another model. |
| **Prompt injection** | Migration source is passed as delimited data, and the model is told to ignore instructions inside it. |

Turn it off with `MIGRATION_REVIEW_AI=false` or `--no-ai`.

## Configuration

```php
// config/migration-review.php
return [
    'ai' => [
        'enabled' => env('MIGRATION_REVIEW_AI', true),
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('MIGRATION_REVIEW_MODEL', 'claude-opus-5-5'),
        'effort' => env('MIGRATION_REVIEW_EFFORT', 'medium'),
        'cache' => ['enabled' => true, 'store' => null, 'ttl' => 60 * 60 * 24 * 30],
    ],
    'large_table_rows' => 1_000_000,   // escalate lock-sensitive findings above this
    'table_stats' => true,
    'sql_preview' => true,
    'fail_on' => env('MIGRATION_REVIEW_FAIL_ON', 'none'),
    'rules' => [/* ... */],
    'disabled_rules' => [],
];
```

## Limitations

- The rules detect operations written with the schema builder and `DB` facade. Migrations that build table names dynamically or hide logic in helper classes get less precise results, and the AI review is the safety net there.
- The SQL preview runs `up()` in pretend mode. Migrations that read data or call application code may not preview, which is reported as a note.
- Row counts are estimates from database statistics. They are accurate enough for "is this table big?", but not exact.

## Development

```bash
composer install
composer test      # PHPUnit; no network calls (the Anthropic SDK runs against a stubbed transport)
composer format    # Laravel Pint
```

Contributions are welcome, especially new rules with tests. Each rule lives in `src/Analysis/Rules` and is tested in `tests/Unit/RulesTest.php`.

## License

MIT. See [LICENSE.md](LICENSE.md).
