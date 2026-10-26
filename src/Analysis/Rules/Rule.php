<?php

namespace GodfreysDragon\MigrationReview\Analysis\Rules;

use GodfreysDragon\MigrationReview\Analysis\Finding;
use GodfreysDragon\MigrationReview\Analysis\RuleContext;
use GodfreysDragon\MigrationReview\Support\Parsed\ParsedMigration;

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
