<?php

namespace GodfreysDragon\MigrationReview\Ai;

use GodfreysDragon\MigrationReview\Analysis\Finding;
use GodfreysDragon\MigrationReview\Support\MigrationFile;

/**
 * Everything the AI reviewer is shown about one migration.
 */
final class ReviewInput
{
    /**
     * @param  list<string>  $sql  SQL the migration would run, if it could be previewed, with bound values left as "?".
     * @param  array<string, int>  $tableRows  Approximate row counts for affected tables.
     * @param  list<Finding>  $staticFindings  What the rule engine already reported.
     * @param  bool  $sqlApproximate  The preview was built from database reads that return nothing in pretend mode.
     */
    public function __construct(
        public readonly MigrationFile $migration,
        public readonly string $driver,
        public readonly array $sql = [],
        public readonly array $tableRows = [],
        public readonly array $staticFindings = [],
        public readonly bool $sqlApproximate = false,
    ) {}
}
