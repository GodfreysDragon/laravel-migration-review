<?php

namespace MigrationReview\Support;

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Produces the SQL a migration's up() would run, using the connection's "pretend" mode.
 *
 * This calls up(). Queries on the migration's connection are captured instead of executed, but
 * everything else in up() runs for real (file writes, HTTP calls, queries on other connections),
 * so only preview code you would be willing to run. Some schema operations (for example ->change()
 * on SQLite) need to inspect the live schema, which returns nothing in pretend mode, so their
 * SQL can be incomplete; the preview reports when that may have happened.
 */
class SqlPreviewer
{
    private const READ_STATEMENT = '/^\s*(select|pragma|show|describe)\b/i';

    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

    public function preview(MigrationFile $file): SqlPreview
    {
        $migration = $this->resolve($file);
        $connection = $this->db->connection(method_exists($migration, 'getConnection') ? $migration->getConnection() : null);

        $previous = $this->db->getDefaultConnection();

        try {
            $this->db->setDefaultConnection($connection->getName() ?? $previous);

            $queries = $connection->pretend(function () use ($migration) {
                if (method_exists($migration, 'up')) {
                    $migration->up();
                }
            });
        } finally {
            $this->db->setDefaultConnection($previous);
        }

        // Drop read-only statements: they are schema introspection (e.g. for ->change()) or lookups, not changes.
        $writes = array_filter($queries, fn (array $query) => preg_match(self::READ_STATEMENT, $query['query']) !== 1);

        return new SqlPreview(
            array_values(array_map(fn (array $query) => $this->inline($query['query'], $query['bindings']), $writes)),
            readsDatabase: count($writes) < count($queries),
        );
    }

    private function resolve(MigrationFile $file): object
    {
        // Legacy named-class migrations: 2014_10_12_000000_create_users_table.php => CreateUsersTable.
        // Requiring one of those twice would redeclare the class, so check first.
        $class = Str::studly(implode('_', array_slice(explode('_', $file->name), 4)));

        if ($class !== '' && class_exists($class, false)) {
            return new $class;
        }

        $migration = require $file->path;

        if (is_object($migration)) {
            return $migration;
        }

        if (! class_exists($class)) {
            throw new RuntimeException("Could not resolve migration class for {$file->name}.");
        }

        return new $class;
    }

    /**
     * @param  array<int|string, mixed>  $bindings
     */
    private function inline(string $sql, array $bindings): string
    {
        foreach ($bindings as $binding) {
            $value = is_string($binding) ? "'".str_replace("'", "''", $binding)."'" : var_export($binding, true);
            $sql = preg_replace('/\?/', str_replace(['\\', '$'], ['\\\\', '\\$'], $value), $sql, 1) ?? $sql;
        }

        return $sql;
    }
}
