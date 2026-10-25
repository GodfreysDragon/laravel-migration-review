<?php

namespace MigrationReview\Analysis\Rules;

use MigrationReview\Analysis\RuleContext;
use MigrationReview\Analysis\Severity;
use MigrationReview\Support\Parsed\ParsedMigration;

/**
 * Building an index or foreign key on a table that already has data.
 */
class IndexOnExistingTableRule extends BaseRule
{
    private const INDEX_METHODS = ['index', 'unique', 'primary', 'fullText', 'spatialIndex', 'foreign'];

    private const INDEX_MODIFIERS = ['index', 'unique', 'primary', 'fullText', 'spatialIndex', 'constrained', 'references'];

    public function id(): string
    {
        return 'index-on-existing-table';
    }

    public function check(ParsedMigration $migration, RuleContext $context): array
    {
        $findings = [];

        foreach ($migration->operations as $operation) {
            if (! $operation->altersExistingTable() || $this->isNewTable($migration, $operation->table, $context)) {
                continue;
            }

            foreach ($operation->blueprintCalls as $call) {
                if (! in_array($call->method(), self::INDEX_METHODS, true) && ! $call->has(...self::INDEX_MODIFIERS)) {
                    continue;
                }

                $columns = implode(', ', $call->columns()) ?: '?';
                $isForeign = $call->method() === 'foreign' || $call->has('constrained', 'references');

                $findings[] = $this->finding(
                    $this->sized(Severity::Medium, $operation->table, $context),
                    ($isForeign ? 'Adds foreign key' : 'Builds index').' on `'.$operation->table.'` ('.$columns.')',
                    ($isForeign
                        ? 'Adding a foreign key validates every existing row and takes locks on both tables while it does.'
                        : 'Building an index reads the whole table and blocks writes while it runs on most setups.')
                        .$this->sizeNote($operation->table, $context),
                    $call->line,
                    match (true) {
                        $context->isPostgres() => 'On PostgreSQL use DB::statement(\'CREATE INDEX CONCURRENTLY ...\') in a migration with `public $withinTransaction = false;`'.($isForeign ? ', and add foreign keys as NOT VALID then VALIDATE CONSTRAINT separately.' : '.'),
                        $context->isMysql() => 'On MySQL prefer ALGORITHM=INPLACE, LOCK=NONE via DB::statement, or an online schema change tool (gh-ost, pt-online-schema-change) for large tables.',
                        default => 'Run this off-peak and check how long it takes against a production-sized copy first.',
                    },
                    $operation->table,
                );
            }
        }

        return $findings;
    }
}
