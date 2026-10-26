<?php

namespace GodfreysDragon\MigrationReview\Support;

/**
 * The SQL a migration would run, as captured in pretend mode.
 */
final class SqlPreview
{
    /**
     * @param  list<string>  $statements  Write statements, with bindings inlined, for display.
     * @param  bool  $readsDatabase  Whether up() read from the database. Reads return nothing in pretend mode,
     *                               so statements built from them (e.g. SQLite table rebuilds for ->change()) can be incomplete.
     * @param  list<string>  $parameterized  The same statements with bindings left as "?". Bindings can hold values
     *                                       computed at runtime (config, env), so this is what leaves the machine.
     */
    public function __construct(
        public readonly array $statements,
        public readonly bool $readsDatabase = false,
        public readonly array $parameterized = [],
    ) {}
}
