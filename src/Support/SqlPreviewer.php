<?php

namespace GodfreysDragon\MigrationReview\Support;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use RuntimeException;
use WeakMap;

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

    /** @var WeakMap<Connection, true> Connections that already have the capture hook. */
    private WeakMap $hooked;

    private ?Connection $capturing = null;

    /** @var list<array{query: string, bindings: array<int|string, mixed>}> */
    private array $captured = [];

    public function __construct(
        private readonly DatabaseManager $db,
    ) {
        $this->hooked = new WeakMap;
    }

    public function preview(MigrationFile $file): SqlPreview
    {
        $migration = $this->resolve($file);
        $connection = $this->db->connection(method_exists($migration, 'getConnection') ? $migration->getConnection() : null);

        // The pretend log has bindings already substituted, so capture each query before that happens.
        if (! isset($this->hooked[$connection])) {
            $connection->beforeExecuting(function (string $query, array $bindings, Connection $on) {
                if ($on === $this->capturing) {
                    $this->captured[] = ['query' => $query, 'bindings' => $bindings];
                }
            });
            $this->hooked[$connection] = true;
        }

        $previous = $this->db->getDefaultConnection();
        $this->drain();

        try {
            $this->db->setDefaultConnection($connection->getName() ?? $previous);
            $this->capturing = $connection;

            $connection->pretend(function () use ($migration) {
                if (method_exists($migration, 'up')) {
                    $migration->up();
                }
            });
        } finally {
            $this->capturing = null;
            $this->db->setDefaultConnection($previous);
        }

        $queries = $this->drain();

        // Drop read-only statements: they are schema introspection (e.g. for ->change()) or lookups, not changes.
        $writes = array_values(array_filter($queries, fn (array $query) => preg_match(self::READ_STATEMENT, $query['query']) !== 1));
        $grammar = $connection->getQueryGrammar();

        return new SqlPreview(
            array_map(fn (array $query) => $grammar->substituteBindingsIntoRawSql($query['query'], $connection->prepareBindings($query['bindings'])), $writes),
            readsDatabase: count($writes) < count($queries),
            parameterized: array_map(fn (array $query) => $query['query'], $writes),
        );
    }

    /**
     * @return list<array{query: string, bindings: array<int|string, mixed>}>
     */
    private function drain(): array
    {
        $captured = $this->captured;
        $this->captured = [];

        return $captured;
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
}
