<?php

namespace MigrationReview\Analysis\Rules;

use MigrationReview\Analysis\RuleContext;
use MigrationReview\Analysis\Severity;
use MigrationReview\Support\Parsed\BlueprintCall;
use MigrationReview\Support\Parsed\ParsedMigration;

/**
 * Adding a NOT NULL column with no default to a table that already has rows.
 */
class NotNullWithoutDefaultRule extends BaseRule
{
    public const COLUMN_TYPES = [
        'bigInteger', 'binary', 'boolean', 'char', 'date', 'dateTime', 'dateTimeTz', 'decimal', 'double',
        'enum', 'float', 'foreignId', 'foreignIdFor', 'foreignUlid', 'foreignUuid', 'geography', 'geometry',
        'integer', 'ipAddress', 'json', 'jsonb', 'longText', 'macAddress', 'mediumInteger', 'mediumText',
        'morphs', 'uuidMorphs', 'ulidMorphs', 'set', 'smallInteger', 'string', 'text', 'time', 'timeTz',
        'timestamp', 'timestampTz', 'tinyInteger', 'tinyText', 'unsignedBigInteger', 'unsignedInteger',
        'unsignedMediumInteger', 'unsignedSmallInteger', 'unsignedTinyInteger', 'ulid', 'uuid', 'year', 'vector',
    ];

    private const SAFE_MODIFIERS = ['default', 'useCurrent', 'storedAs', 'virtualAs', 'generatedAs', 'autoIncrement', 'change'];

    public function id(): string
    {
        return 'not-null-without-default';
    }

    public function check(ParsedMigration $migration, RuleContext $context): array
    {
        $findings = [];

        foreach ($migration->operations as $operation) {
            if (! $operation->altersExistingTable() || $migration->createsTable($operation->table)) {
                continue;
            }

            foreach ($operation->blueprintCalls as $call) {
                if (! in_array($call->method(), self::COLUMN_TYPES, true) || $this->isNullableOrDefaulted($call)) {
                    continue;
                }

                $column = $call->columns()[0] ?? '?';
                $severity = $context->isMysql() ? Severity::Medium : Severity::High;
                $behaviour = $context->isMysql()
                    ? 'MySQL fills existing rows with an implicit zero value (0, \'\', 0000-00-00), which silently creates bad data, and fails outright in strict mode for some types.'
                    : 'The migration fails as soon as the table has any rows, because existing rows would have NULL in a NOT NULL column.';

                $findings[] = $this->finding(
                    $severity,
                    "Adds NOT NULL column `{$column}` to `{$operation->table}` without a default",
                    $behaviour.$this->sizeNote($operation->table, $context),
                    $call->line,
                    'Add it as ->nullable() (or with ->default(...)), backfill existing rows, then make it NOT NULL in a follow-up migration.',
                    $operation->table,
                );
            }
        }

        return $findings;
    }

    private function isNullableOrDefaulted(BlueprintCall $call): bool
    {
        foreach (array_slice($call->links, 1) as $link) {
            if ($link['method'] === 'nullable' && ($link['args'][0] ?? true) !== false) {
                return true;
            }

            if (in_array($link['method'], self::SAFE_MODIFIERS, true)) {
                return true;
            }
        }

        return false;
    }
}
