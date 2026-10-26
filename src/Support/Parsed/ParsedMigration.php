<?php

namespace GodfreysDragon\MigrationReview\Support\Parsed;

use GodfreysDragon\MigrationReview\Support\MigrationFile;

final class ParsedMigration
{
    /**
     * @param  list<SchemaOperation>  $operations  Schema calls in up(), in source order.
     * @param  list<CodeCall>  $codeCalls  Other notable calls in up().
     * @param  ?int  $downStatementCount  Statements in down(); null when there is no down() at all.
     */
    public function __construct(
        public readonly MigrationFile $file,
        public readonly array $operations,
        public readonly array $codeCalls,
        public readonly bool $hasUp,
        public readonly ?int $downStatementCount,
        public readonly ?int $classLine = null,
        public readonly ?string $parseError = null,
    ) {}

    /**
     * Tables created by this same migration. Operations on them are safe: they have no rows yet.
     *
     * @return list<string>
     */
    public function createdTables(): array
    {
        $tables = [];

        foreach ($this->operations as $operation) {
            if ($operation->isCreate() && $operation->table !== null) {
                $tables[] = $operation->table;
            }
        }

        return $tables;
    }

    public function createsTable(?string $table): bool
    {
        return $table !== null && in_array($table, $this->createdTables(), true);
    }
}
