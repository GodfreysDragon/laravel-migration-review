<?php

namespace MigrationReview\Analysis\Rules;

use MigrationReview\Analysis\RuleContext;
use MigrationReview\Analysis\Severity;
use MigrationReview\Support\Parsed\CodeCall;
use MigrationReview\Support\Parsed\ParsedMigration;

/**
 * Query-builder writes and Eloquent model usage inside up().
 */
class DataManipulationRule extends BaseRule
{
    public function id(): string
    {
        return 'data-manipulation';
    }

    public function check(ParsedMigration $migration, RuleContext $context): array
    {
        $findings = [];

        foreach ($migration->codeCalls as $call) {
            if ($call->kind === CodeCall::QUERY_WRITE) {
                $findings[] = $this->finding(
                    str_ends_with($call->target, '->truncate') ? Severity::High : $this->sized(Severity::Medium, $call->table, $context),
                    "Modifies data with {$call->target}()",
                    'Data changes inside a migration run in one long statement or transaction, holding row locks and blocking the deploy while they run.'.$this->sizeNote($call->table, $context),
                    $call->line,
                    'Move backfills into a queued job or Artisan command that processes rows in chunks (chunkById), and keep the migration schema-only.',
                    $call->table,
                );
            } elseif ($call->kind === CodeCall::MODEL) {
                $findings[] = $this->finding(
                    Severity::Medium,
                    "Uses application model {$call->target}()",
                    'Migrations are replayed on fresh databases long after they are written. If this model later changes (columns, casts, scopes, observers, or is deleted), this migration breaks.',
                    $call->line,
                    'Use DB::table() inside migrations so they depend only on the schema at that point in time.',
                );
            }
        }

        return $findings;
    }
}
