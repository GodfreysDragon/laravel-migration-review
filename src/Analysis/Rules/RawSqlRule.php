<?php

namespace MigrationReview\Analysis\Rules;

use MigrationReview\Analysis\RuleContext;
use MigrationReview\Analysis\Severity;
use MigrationReview\Support\Parsed\CodeCall;
use MigrationReview\Support\Parsed\ParsedMigration;

/**
 * DB::statement() and friends: invisible to the schema-builder rules, so they need a human look.
 */
class RawSqlRule extends BaseRule
{
    private const DESTRUCTIVE = '/\b(DROP\s+(TABLE|COLUMN|SCHEMA|DATABASE)|TRUNCATE|DELETE\s+FROM(?![\s\S]*\bWHERE\b))/i';

    private const TABLE_REFERENCE = '/\b(?:ON|TABLE|INTO|FROM|UPDATE|JOIN)\s+(?:IF\s+(?:NOT\s+)?EXISTS\s+)?(?:ONLY\s+)?[`"\[]?(?:\w+[`"\]]?\.[`"\[]?)?(\w+)/i';

    public function id(): string
    {
        return 'raw-sql';
    }

    public function check(ParsedMigration $migration, RuleContext $context): array
    {
        $findings = [];

        foreach ($migration->codeCalls as $call) {
            if ($call->kind !== CodeCall::RAW_SQL) {
                continue;
            }

            $sql = $call->sql;
            $preview = $sql !== null ? ' SQL: '.mb_strimwidth(preg_replace('/\s+/', ' ', $sql) ?? '', 0, 160, '…') : '';

            if ($sql !== null && preg_match(self::DESTRUCTIVE, $sql) === 1) {
                $severity = Severity::High;
                $message = 'This statement deletes data or schema objects, and raw SQL is not checked by the other rules.';
            } elseif ($sql !== null && $this->onlyTouchesNewObjects($sql, $migration)) {
                $severity = Severity::Low;
                $message = 'Raw SQL only affects tables created in this migration (or installs an extension), so there is no existing data at risk, but it is not portable across databases.';
            } else {
                $severity = Severity::Medium;
                $message = 'Raw SQL bypasses the schema builder, is not portable across databases, and cannot be fully checked statically.';
            }

            $findings[] = $this->finding(
                $severity,
                "Runs raw SQL via {$call->target}",
                $message.$preview,
                $call->line,
                $severity === Severity::Low ? null : 'Review the statement by hand and test it against a production-sized copy of the data.',
            );
        }

        return $findings;
    }

    private function onlyTouchesNewObjects(string $sql, ParsedMigration $migration): bool
    {
        if (preg_match('/^\s*CREATE\s+EXTENSION\b/i', $sql) === 1) {
            return true;
        }

        preg_match_all(self::TABLE_REFERENCE, $sql, $matches);
        $tables = array_unique(array_map('strtolower', $matches[1]));
        $created = array_map('strtolower', $migration->createdTables());

        return $tables !== [] && array_diff($tables, $created) === [];
    }
}
