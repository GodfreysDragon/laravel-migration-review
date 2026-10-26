<?php

namespace GodfreysDragon\MigrationReview\Analysis\Rules;

use GodfreysDragon\MigrationReview\Analysis\Finding;
use GodfreysDragon\MigrationReview\Analysis\RuleContext;
use GodfreysDragon\MigrationReview\Analysis\Severity;
use GodfreysDragon\MigrationReview\Support\Parsed\ParsedMigration;

abstract class BaseRule implements Rule
{
    protected function finding(
        Severity $severity,
        string $title,
        string $message,
        ?int $line = null,
        ?string $suggestion = null,
        ?string $table = null,
    ): Finding {
        return new Finding($this->id(), $severity, $title, $message, $line, $suggestion, $table);
    }

    /**
     * Whether the table is created by this migration or an earlier one in the same run, so it has no rows yet.
     */
    protected function isNewTable(ParsedMigration $migration, ?string $table, RuleContext $context): bool
    {
        return $migration->createsTable($table) || $context->isNew($table);
    }

    /**
     * Bump the severity one level when the table is known to be large.
     */
    protected function sized(Severity $severity, ?string $table, RuleContext $context): Severity
    {
        return $context->isLarge($table) ? $severity->escalate() : $severity;
    }

    /**
     * A short sentence about the table size, or an empty string when unknown.
     */
    protected function sizeNote(?string $table, RuleContext $context): string
    {
        $rows = $context->rows($table);

        if ($rows === null) {
            return '';
        }

        return sprintf(' `%s` currently has about %s rows.', $table, self::humanCount($rows));
    }

    public static function humanCount(int $rows): string
    {
        return match (true) {
            $rows >= 1_000_000_000 => round($rows / 1_000_000_000, 1).'B',
            $rows >= 1_000_000 => round($rows / 1_000_000, 1).'M',
            $rows >= 1_000 => round($rows / 1_000, 1).'K',
            default => (string) $rows,
        };
    }
}
