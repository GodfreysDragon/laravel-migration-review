<?php

namespace GodfreysDragon\MigrationReview\Analysis\Rules;

use GodfreysDragon\MigrationReview\Analysis\Finding;
use GodfreysDragon\MigrationReview\Analysis\RuleContext;
use GodfreysDragon\MigrationReview\Analysis\Severity;
use GodfreysDragon\MigrationReview\Support\Parsed\ParsedMigration;

class DropColumnRule extends BaseRule
{
    /**
     * Blueprint methods that remove columns, with the columns they remove when not given explicitly.
     */
    private const DROPPERS = [
        'dropColumn' => null,
        'dropConstrainedForeignId' => null,
        'dropConstrainedForeignIdFor' => null,
        'dropMorphs' => null,
        'dropTimestamps' => ['created_at', 'updated_at'],
        'dropTimestampsTz' => ['created_at', 'updated_at'],
        'dropSoftDeletes' => ['deleted_at'],
        'dropSoftDeletesTz' => ['deleted_at'],
        'dropRememberToken' => ['remember_token'],
    ];

    public function id(): string
    {
        return 'drop-column';
    }

    public function check(ParsedMigration $migration, RuleContext $context): array
    {
        $findings = [];

        foreach ($migration->operations as $operation) {
            if ($operation->method === 'dropColumns') {
                $columns = is_array($operation->args[1] ?? null) ? $operation->args[1] : [$operation->args[1] ?? '?'];
                $findings[] = $this->dropFinding($operation->table, $columns, $operation->line, $context);

                continue;
            }

            if ($this->isNewTable($migration, $operation->table, $context)) {
                continue;
            }

            foreach ($operation->blueprintCalls as $call) {
                if (! array_key_exists($call->method(), self::DROPPERS)) {
                    continue;
                }

                $columns = self::DROPPERS[$call->method()] ?? $call->columns();

                if ($call->method() === 'dropMorphs' && $columns !== []) {
                    $columns = [$columns[0].'_type', $columns[0].'_id'];
                }

                $findings[] = $this->dropFinding($operation->table, $columns, $call->line, $context);
            }
        }

        return $findings;
    }

    /**
     * @param  array<mixed>  $columns
     */
    private function dropFinding(?string $table, array $columns, int $line, RuleContext $context): Finding
    {
        $columns = array_values(array_filter($columns, 'is_string'));
        $list = $columns === [] ? 'columns' : implode(', ', array_map(fn ($c) => "`{$c}`", $columns));
        $on = $table !== null ? " from `{$table}`" : '';

        return $this->finding(
            Severity::High,
            "Drops {$list}{$on}",
            "The column data is deleted permanently, and the release still running during deploy will fail if it reads or writes {$list}.".$this->sizeNote($table, $context),
            $line,
            'Deploy in two steps: first ship code that no longer uses the column (and remove it from $fillable, casts and resources), then drop it in a later release.',
            $table,
        );
    }
}
