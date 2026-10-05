<?php

namespace MigrationReview\Analysis\Rules;

use MigrationReview\Analysis\Finding;
use MigrationReview\Analysis\RuleContext;
use MigrationReview\Support\Parsed\ParsedMigration;

interface Rule
{
    /**
     * Stable kebab-case identifier, used in output and in ignore comments.
     */
    public function id(): string;

    /**
     * @return list<Finding>
     */
    public function check(ParsedMigration $migration, RuleContext $context): array;
}
