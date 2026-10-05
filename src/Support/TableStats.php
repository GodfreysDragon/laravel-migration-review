<?php

namespace MigrationReview\Support;

use Illuminate\Database\Connection;
use Throwable;

/**
 * Approximate row counts for existing tables, read from database statistics.
 *
 * MySQL, PostgreSQL and SQL Server expose cheap estimates; SQLite has none, so it runs COUNT(*),
 * which is fine for the small databases SQLite is used with.
 */
class TableStats
{
    /**
     * @param  list<string>  $tables  Unprefixed table names.
     * @return array<string, int>
     */
    public function rows(Connection $connection, array $tables): array
    {
        $tables = array_values(array_unique(array_filter($tables)));

        if ($tables === []) {
            return [];
        }

        try {
            $prefix = $connection->getTablePrefix();
            $estimates = $this->estimates($connection, array_map(fn ($t) => $prefix.$t, $tables));
        } catch (Throwable) {
            return [];
        }

        $rows = [];

        foreach ($tables as $table) {
            if (isset($estimates[$prefix.$table])) {
                $rows[$table] = $estimates[$prefix.$table];
            }
        }

        return $rows;
    }

    /**
     * @param  list<string>  $tables  Prefixed table names.
     * @return array<string, int>
     */
    private function estimates(Connection $connection, array $tables): array
    {
        $placeholders = implode(', ', array_fill(0, count($tables), '?'));

        $results = match ($connection->getDriverName()) {
            'mysql', 'mariadb' => $connection->select(
                "select table_name as name, table_rows as row_count from information_schema.tables where table_schema = database() and table_name in ({$placeholders})",
                $tables,
            ),
            'pgsql' => $connection->select(
                "select c.relname as name, c.reltuples::bigint as row_count from pg_class c join pg_namespace n on n.oid = c.relnamespace where c.relkind in ('r', 'p') and n.nspname = current_schema() and c.relname in ({$placeholders})",
                $tables,
            ),
            'sqlsrv' => $connection->select(
                "select t.name as name, sum(p.rows) as row_count from sys.tables t join sys.partitions p on p.object_id = t.object_id and p.index_id in (0, 1) where t.name in ({$placeholders}) group by t.name",
                $tables,
            ),
            'sqlite' => $this->sqliteCounts($connection, $tables),
            default => [],
        };

        $rows = [];

        foreach ($results as $result) {
            $result = (array) $result;
            $count = (int) ($result['row_count'] ?? $result['ROW_COUNT'] ?? -1);

            // PostgreSQL reports -1 for tables that have never been analyzed.
            if ($count >= 0) {
                $rows[(string) ($result['name'] ?? $result['NAME'])] = $count;
            }
        }

        return $rows;
    }

    /**
     * @param  list<string>  $tables
     * @return list<array{name: string, row_count: int}>
     */
    private function sqliteCounts(Connection $connection, array $tables): array
    {
        $existing = array_column(array_map(fn ($r) => (array) $r, $connection->select("select name from sqlite_master where type = 'table'")), 'name');
        $results = [];

        foreach (array_intersect($tables, $existing) as $table) {
            $results[] = [
                'name' => $table,
                'row_count' => (int) $connection->scalar('select count(*) from "'.str_replace('"', '""', $table).'"'),
            ];
        }

        return $results;
    }
}
