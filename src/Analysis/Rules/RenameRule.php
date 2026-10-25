<?php

namespace MigrationReview\Analysis\Rules;

use MigrationReview\Analysis\RuleContext;
use MigrationReview\Analysis\Severity;
use MigrationReview\Support\Parsed\ParsedMigration;

class RenameRule extends BaseRule
{
    public function id(): string
    {
        return 'rename';
    }

    public function check(ParsedMigration $migration, RuleContext $context): array
    {
        $findings = [];
        $suggestion = 'For zero-downtime deploys, add the new name, write to both, backfill, switch reads, then remove the old name in a later release.';

        foreach ($migration->operations as $operation) {
            if ($operation->method === 'rename') {
                $to = is_string($operation->args[1] ?? null) ? $operation->args[1] : '?';
                $findings[] = $this->finding(
                    Severity::Medium,
                    "Renames table `{$operation->table}` to `{$to}`",
                    'Code from the previous release still queries the old table name until every server has the new code, so requests fail during the deploy window.',
                    $operation->line,
                    $suggestion,
                    $operation->table,
                );

                continue;
            }

            if ($this->isNewTable($migration, $operation->table, $context)) {
                continue;
            }

            foreach ($operation->blueprintCalls as $call) {
                if ($call->method() !== 'renameColumn') {
                    continue;
                }

                [$from, $to] = [$call->args()[0] ?? '?', $call->args()[1] ?? '?'];
                $findings[] = $this->finding(
                    $this->sized(Severity::Medium, $operation->table, $context),
                    "Renames column `{$from}` to `{$to}` on `{$operation->table}`",
                    'Code from the previous release still uses the old column name until the deploy finishes, so queries against it fail in the meantime.'
                        .($context->isMysql() ? ' On MySQL versions before 8.0 this also rebuilds the table.' : '')
                        .$this->sizeNote($operation->table, $context),
                    $call->line,
                    $suggestion,
                    $operation->table,
                );
            }
        }

        return $findings;
    }
}
