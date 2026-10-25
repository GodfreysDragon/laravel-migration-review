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
     * @param  list<string>  $newTables  Tables an earlier migration in the same run creates, so they will be empty.
     */
    public function __construct(
        public readonly string $driver = 'mysql',
        public readonly array $tableRows = [],
        public readonly int $largeTableRows = 1_000_000,
        public readonly array $newTables = [],
    ) {}

    /**
     * @param  list<string>  $tables
     */
    public function withNewTables(array $tables): self
    {
        return new self($this->driver, $this->tableRows, $this->largeTableRows, $tables);
    }

    public function isNew(?string $table): bool
    {
        return $table !== null && in_array($table, $this->newTables, true);
    }

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
