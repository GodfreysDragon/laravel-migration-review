<?php

namespace GodfreysDragon\MigrationReview\Analysis\Rules;

use GodfreysDragon\MigrationReview\Analysis\RuleContext;
use GodfreysDragon\MigrationReview\Analysis\Severity;
use GodfreysDragon\MigrationReview\Support\Parsed\ParsedMigration;

class ColumnChangeRule extends BaseRule
{
    /**
     * Modifiers that Laravel 11+ drops when they are not restated on ->change().
     */
    private const RESTATED_MODIFIERS = ['nullable', 'default', 'comment', 'unsigned', 'charset', 'collation', 'autoIncrement', 'useCurrent', 'useCurrentOnUpdate'];

    public function id(): string
    {
        return 'column-change';
    }

    public function check(ParsedMigration $migration, RuleContext $context): array
    {
        $findings = [];

        foreach ($migration->operations as $operation) {
            foreach ($operation->blueprintCalls as $call) {
                if (! $call->has('change')) {
                    continue;
                }

                $column = $call->columns()[0] ?? '?';
                $type = $call->method();
                $message = "Changing `{$column}` to `{$type}` rewrites the column. On large tables this can lock writes for a long time, and narrowing a type or length can truncate or reject existing values."
                    .$this->sizeNote($operation->table, $context);

                if (! $call->has(...self::RESTATED_MODIFIERS)) {
                    $message .= ' No modifiers are restated: since Laravel 11, ->change() drops attributes such as nullable, default and comment unless you repeat them.';
                }

                $findings[] = $this->finding(
                    $this->sized(Severity::Medium, $operation->table, $context),
                    "Alters column `{$column}` on `{$operation->table}`",
                    $message,
                    $call->line,
                    'Check existing data fits the new definition (e.g. SELECT MAX(LENGTH(col))), restate every modifier you want to keep, and run it off-peak or with an online schema change tool on big tables.',
                    $operation->table,
                );
            }
        }

        return $findings;
    }
}
