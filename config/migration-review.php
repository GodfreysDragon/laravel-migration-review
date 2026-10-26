<?php

use MigrationReview\Analysis\Rules;

return [

    /*
    |--------------------------------------------------------------------------
    | AI review
    |--------------------------------------------------------------------------
    |
    | When an Anthropic API key is set, each migration is also reviewed by Claude,
    | which sees the source, the SQL it would run, table sizes and the rule-engine
    | findings, and reports what the rules missed. Without a key, only the static
    | rules run. Reviews are cached by content hash, so unchanged migrations are
    | never sent twice.
    |
    */

    'ai' => [
        'enabled' => env('MIGRATION_REVIEW_AI', true),
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('MIGRATION_REVIEW_MODEL', 'claude-opus-5-5'),
        'effort' => env('MIGRATION_REVIEW_EFFORT', 'medium'),
        'max_tokens' => 16000,
        'timeout' => 120,

        'cache' => [
            'enabled' => true,
            'store' => env('MIGRATION_REVIEW_CACHE_STORE'),
            'ttl' => 60 * 60 * 24 * 30,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Context
    |--------------------------------------------------------------------------
    |
    | Lock-sensitive findings (indexes, column changes, backfills) are raised one
    | severity level when a table has at least this many rows. Row counts come
    | from database statistics and are only read when `table_stats` is on.
    |
    | The SQL preview calls each migration's up() in pretend mode. Queries on the
    | migration's connection are captured, not run, but any other code in up()
    | runs with the app's full access. Turn it off for code you don't trust.
    |
    */

    'large_table_rows' => 1_000_000,

    'table_stats' => true,

    'sql_preview' => env('MIGRATION_REVIEW_SQL_PREVIEW', true),

    /*
    |--------------------------------------------------------------------------
    | Exit code
    |--------------------------------------------------------------------------
    |
    | The command exits with a failure code when any finding is at least this
    | severity: "low", "medium", "high" or "none". Use "high" in CI.
    |
    */

    'fail_on' => env('MIGRATION_REVIEW_FAIL_ON', 'none'),

    /*
    |--------------------------------------------------------------------------
    | Rules
    |--------------------------------------------------------------------------
    |
    | Add your own classes implementing MigrationReview\Analysis\Rules\Rule, or
    | disable built-in ones by id. Single findings can be silenced in a migration
    | with a `// migration-review-ignore: <rule-id>` comment on or above the line.
    |
    */

    'rules' => [
        Rules\DropTableRule::class,
        Rules\DropColumnRule::class,
        Rules\RenameRule::class,
        Rules\ColumnChangeRule::class,
        Rules\NotNullWithoutDefaultRule::class,
        Rules\IndexOnExistingTableRule::class,
        Rules\DataManipulationRule::class,
        Rules\RawSqlRule::class,
        Rules\MissingDownRule::class,
    ],

    'disabled_rules' => [
        // 'missing-down',
    ],

];
