<?php

namespace MigrationReview\Analysis\Rules;

use MigrationReview\Analysis\RuleContext;
use MigrationReview\Analysis\Severity;
use MigrationReview\Support\Parsed\ParsedMigration;

class DropTableRule extends BaseRule
{
    public function id(): string
    {
        return 'drop-table';
    }

    public function check(ParsedMigration $migration, RuleContext $context): array
    {
        $findings = [];

        foreach ($migration->operations as $operation) {
            if (! in_array($operation->method, ['drop', 'dropIfExists', 'dropAllTables', 'dropAllViews'], true)) {
                continue;
            }

            if ($this->isNewTable($migration, $operation->table, $context)) {
                continue;
            }

            $what = $operation->table !== null ? "table `{$operation->table}`" : 'tables';

            $findings[] = $this->finding(
                Severity::High,
                "Drops {$what}",
                "Dropping {$what} permanently deletes its data and cannot be undone by rolling back.".$this->sizeNote($operation->table, $context),
                $operation->line,
                'Confirm a backup exists and nothing still reads this table. Consider renaming it (e.g. `_archived_'.($operation->table ?? 'table').'`) first and dropping it in a later release.',
                $operation->table,
            );
        }

        return $findings;
    }
}
