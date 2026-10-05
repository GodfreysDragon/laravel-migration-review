<?php

namespace MigrationReview\Support;

use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Produces the SQL a migration's up() would run, using the connection's "pretend" mode.
 *
 * Nothing is executed against the database. Some schema operations (for example ->change()
 * on SQLite) need to inspect the live schema, so previews can fail; callers treat that as a note.
 */
class SqlPreviewer
{
    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

    /**
     * @return list<string>
     */
    public function preview(MigrationFile $file): array
    {
        $migration = $this->resolve($file);
        $connection = $this->db->connection(method_exists($migration, 'getConnection') ? $migration->getConnection() : null);

        /** @var ConnectionResolverInterface $resolver */
        $resolver = $this->db;
        $previous = $resolver->getDefaultConnection();

        try {
            $resolver->setDefaultConnection($connection->getName());

            $queries = $connection->pretend(function () use ($migration) {
                if (method_exists($migration, 'up')) {
                    $migration->up();
                }
            });
        } finally {
            $resolver->setDefaultConnection($previous);
        }

        // Drop read-only statements: they are schema introspection (e.g. for ->change()) or lookups, not changes.
        $writes = array_filter($queries, fn (array $query) => preg_match('/^\s*(select|pragma|show|describe)\b/i', $query['query']) !== 1);

        return array_values(array_map(fn (array $query) => $this->inline($query['query'], $query['bindings'] ?? []), $writes));
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
