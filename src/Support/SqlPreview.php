<?php

namespace MigrationReview\Support;

/**
 * The SQL a migration would run, as captured in pretend mode.
 */
final class SqlPreview
{
    /**
     * @param  list<string>  $statements  Write statements, with bindings inlined.
     * @param  bool  $readsDatabase  Whether up() read from the database. Reads return nothing in pretend mode,
     *                               so statements built from them (e.g. SQLite table rebuilds for ->change()) can be incomplete.
     */
    public function __construct(
        public readonly array $statements,
        public readonly bool $readsDatabase = false,
    ) {}
}
