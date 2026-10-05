<?php

namespace MigrationReview\Analysis;

use MigrationReview\Support\MigrationFile;

/**
 * Everything the reviewer learned about one migration file.
 */
final class MigrationReport
{
    /**
     * @param  list<Finding>  $findings
     * @param  list<string>  $sql
     * @param  list<string>  $notes  Non-fatal problems hit while reviewing (e.g. SQL preview or AI call failed).
     */
    public function __construct(
        public readonly MigrationFile $migration,
        public array $findings = [],
        public array $sql = [],
        public ?string $aiSummary = null,
        public array $notes = [],
    ) {}

    public function highestSeverity(): ?Severity
    {
        $highest = null;

        foreach ($this->findings as $finding) {
            if ($highest === null || $finding->severity->rank() > $highest->rank()) {
                $highest = $finding->severity;
            }
        }

        return $highest;
    }

    /**
     * Findings ordered most severe first, then by line.
     *
     * @return list<Finding>
     */
    public function sortedFindings(): array
    {
        $findings = $this->findings;

        usort($findings, fn (Finding $a, Finding $b) => [$b->severity->rank(), $a->line ?? PHP_INT_MAX]
            <=> [$a->severity->rank(), $b->line ?? PHP_INT_MAX]);

        return $findings;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'migration' => $this->migration->name,
            'path' => $this->migration->relativePath(),
            'highest_severity' => $this->highestSeverity()?->value,
            'ai_summary' => $this->aiSummary,
            'findings' => array_map(fn (Finding $f) => $f->toArray(), $this->sortedFindings()),
            'sql' => $this->sql,
            'notes' => $this->notes,
        ];
    }
}
