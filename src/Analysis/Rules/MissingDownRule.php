<?php

namespace MigrationReview\Analysis\Rules;

use MigrationReview\Analysis\RuleContext;
use MigrationReview\Analysis\Severity;
use MigrationReview\Support\Parsed\ParsedMigration;

class MissingDownRule extends BaseRule
{
    public function id(): string
    {
        return 'missing-down';
    }

    public function check(ParsedMigration $migration, RuleContext $context): array
    {
        if (! $migration->hasUp || ($migration->downStatementCount ?? 0) > 0) {
            return [];
        }

        return [$this->finding(
            Severity::Low,
            $migration->downStatementCount === null ? 'No down() method' : 'Empty down() method',
            'This migration cannot be rolled back with `php artisan migrate:rollback`, so a bad deploy has to be fixed forward.',
            $migration->classLine,
            'Implement down() to reverse up(), or document why the change is intentionally irreversible.',
        )];
    }
}
