<?php

namespace MigrationReview\Analysis;

use MigrationReview\Analysis\Rules\Rule;
use MigrationReview\Support\MigrationFile;
use MigrationReview\Support\MigrationParser;
use MigrationReview\Support\Parsed\ParsedMigration;

/**
 * Runs every enabled rule against a migration and applies inline ignore comments.
 *
 * A finding is suppressed by a comment on its line or the line above:
 *   // migration-review-ignore: drop-column, rename
 *   // migration-review-ignore            (ignores every rule on that line)
 */
class StaticAnalyzer
{
    /**
     * @param  list<Rule>  $rules
     * @param  list<string>  $disabledRules  Rule ids to skip entirely.
     */
    public function __construct(
        private readonly MigrationParser $parser,
        private readonly array $rules,
        private readonly array $disabledRules = [],
    ) {}

    public function parse(MigrationFile $file): ParsedMigration
    {
        return $this->parser->parse($file);
    }

    /**
     * @return list<Finding>
     */
    public function analyze(ParsedMigration $migration, RuleContext $context): array
    {
        $findings = [];

        foreach ($this->rules as $rule) {
            if (in_array($rule->id(), $this->disabledRules, true)) {
                continue;
            }

            foreach ($rule->check($migration, $context) as $finding) {
                if (! $this->isIgnored($finding, $migration->file)) {
                    $findings[] = $finding;
                }
            }
        }

        return $findings;
    }

    private function isIgnored(Finding $finding, MigrationFile $file): bool
    {
        if ($finding->line === null) {
            return false;
        }

        $lines = preg_split('/\R/', $file->contents) ?: [];

        foreach ([$finding->line - 1, $finding->line - 2] as $index) {
            $line = $lines[$index] ?? '';

            if (preg_match('/migration-review-ignore(?::\s*([\w,\s-]+))?/', $line, $match) !== 1) {
                continue;
            }

            $ids = isset($match[1]) ? array_map('trim', explode(',', $match[1])) : [];

            if ($ids === [] || $ids === [''] || in_array($finding->rule, $ids, true)) {
                return true;
            }
        }

        return false;
    }
}
