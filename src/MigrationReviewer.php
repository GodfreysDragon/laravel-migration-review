<?php

namespace MigrationReview;

use Illuminate\Database\Connection;
use MigrationReview\Ai\AiReviewer;
use MigrationReview\Ai\AiReviewFailed;
use MigrationReview\Ai\ReviewInput;
use MigrationReview\Analysis\Finding;
use MigrationReview\Analysis\MigrationReport;
use MigrationReview\Analysis\RuleContext;
use MigrationReview\Analysis\Severity;
use MigrationReview\Analysis\StaticAnalyzer;
use MigrationReview\Support\MigrationFile;
use MigrationReview\Support\Parsed\ParsedMigration;
use MigrationReview\Support\SqlPreviewer;
use MigrationReview\Support\TableStats;
use Throwable;

/**
 * Reviews a set of migration files: rules first, then SQL preview and table sizes for context,
 * then (optionally) an AI pass that sees all of it.
 */
class MigrationReviewer
{
    public function __construct(
        private readonly StaticAnalyzer $analyzer,
        private readonly SqlPreviewer $sqlPreviewer,
        private readonly TableStats $tableStats,
        private readonly ?AiReviewer $aiReviewer = null,
        private readonly int $largeTableRows = 1_000_000,
    ) {}

    /**
     * @param  list<MigrationFile>  $files
     * @param  callable(MigrationFile): void|null  $progress  Called before each file is reviewed.
     * @return list<MigrationReport>
     */
    public function review(
        array $files,
        Connection $connection,
        bool $withSql = true,
        bool $withTableStats = true,
        bool $withAi = true,
        ?callable $progress = null,
    ): array {
        $parsed = array_map(fn (MigrationFile $file) => $this->analyzer->parse($file), $files);

        $tableRows = $withTableStats ? $this->tableStats->rows($connection, $this->existingTables($parsed)) : [];
        $context = new RuleContext($connection->getDriverName(), $tableRows, $this->largeTableRows);

        $reports = [];
        $newTables = [];

        foreach ($parsed as $migration) {
            if ($progress !== null) {
                $progress($migration->file);
            }

            $reports[] = $this->reviewOne($migration, $context->withNewTables(array_keys($newTables)), $withSql, $withAi && $this->aiReviewer !== null);

            // Later migrations in this run will find these tables empty, unless the create already ran.
            foreach ($migration->createdTables() as $table) {
                if (! $this->tableExists($connection, $table)) {
                    $newTables[$table] = true;
                }
            }
        }

        return $reports;
    }

    public function hasAi(): bool
    {
        return $this->aiReviewer !== null;
    }

    private function reviewOne(ParsedMigration $migration, RuleContext $context, bool $withSql, bool $withAi): MigrationReport
    {
        $report = new MigrationReport($migration->file);

        if ($migration->parseError !== null) {
            $report->findings[] = new Finding('parse-error', Severity::High, 'Migration could not be parsed', $migration->parseError);

            return $report;
        }

        $report->findings = $this->analyzer->analyze($migration, $context);

        if ($withSql) {
            try {
                $report->sql = $this->sqlPreviewer->preview($migration->file);
            } catch (Throwable $e) {
                $report->notes[] = 'SQL preview unavailable: '.$e->getMessage();
            }
        }

        if ($withAi && $this->aiReviewer !== null) {
            $tables = array_filter(array_map(fn ($op) => $op->table, $migration->operations));

            try {
                $review = $this->aiReviewer->review(new ReviewInput(
                    migration: $migration->file,
                    driver: $context->driver,
                    sql: $report->sql,
                    tableRows: array_intersect_key($context->tableRows, array_flip($tables)),
                    staticFindings: $report->findings,
                ));

                $report->aiSummary = $review->summary !== '' ? $review->summary : null;
                array_push($report->findings, ...$review->findings);
            } catch (AiReviewFailed $e) {
                $report->notes[] = $e->getMessage();
            }
        }

        return $report;
    }

    private function tableExists(Connection $connection, string $table): bool
    {
        try {
            return $connection->getSchemaBuilder()->hasTable($table);
        } catch (Throwable) {
            // No reachable database: the create in this run has not happened yet.
            return false;
        }
    }

    /**
     * Tables the migrations touch that are expected to exist already.
     *
     * @param  list<ParsedMigration>  $parsed
     * @return list<string>
     */
    private function existingTables(array $parsed): array
    {
        $tables = [];

        foreach ($parsed as $migration) {
            foreach ($migration->operations as $operation) {
                if ($operation->table !== null && ! $operation->isCreate()) {
                    $tables[] = $operation->table;
                }
            }

            foreach ($migration->codeCalls as $call) {
                if ($call->table !== null) {
                    $tables[] = $call->table;
                }
            }
        }

        return array_values(array_unique($tables));
    }
}
