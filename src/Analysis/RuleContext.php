<?php

namespace MigrationReview\Analysis;

/**
 * Facts about the target database that let rules judge how risky an operation really is.
 */
final class RuleContext
{
    /**
     * @param  string  $driver  Connection driver: mysql, mariadb, pgsql, sqlite, sqlsrv.
     * @param  array<string, int>  $tableRows  Approximate row counts for existing tables, when known.
     */
    public function __construct(
        public readonly string $driver = 'mysql',
        public readonly array $tableRows = [],
        public readonly int $largeTableRows = 1_000_000,
    ) {}

    public function rows(?string $table): ?int
    {
        return $table === null ? null : ($this->tableRows[$table] ?? null);
    }

    public function isLarge(?string $table): bool
    {
        $rows = $this->rows($table);

        return $rows !== null && $rows >= $this->largeTableRows;
    }

    public function isPostgres(): bool
    {
        return $this->driver === 'pgsql';
    }

    public function isMysql(): bool
    {
        return in_array($this->driver, ['mysql', 'mariadb'], true);
    }
}
