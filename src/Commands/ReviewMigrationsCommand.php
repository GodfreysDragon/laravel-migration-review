<?php

namespace MigrationReview\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use MigrationReview\Analysis\MigrationReport;
use MigrationReview\Analysis\Severity;
use MigrationReview\MigrationReviewer;
use MigrationReview\Output\GithubRenderer;
use MigrationReview\Output\JsonRenderer;
use MigrationReview\Output\MarkdownRenderer;
use MigrationReview\Output\Renderer;
use MigrationReview\Output\TextRenderer;
use MigrationReview\Support\MigrationFile;
use MigrationReview\Support\MigrationLocator;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;

class ReviewMigrationsCommand extends Command
{
    protected $signature = 'migrate:review
        {paths?* : Migration files or directories to review (default: pending migrations)}
        {--all : Review every migration, not only pending ones}
        {--database= : The database connection to inspect}
        {--format=text : Output format: text, json, markdown or github}
        {--output= : Also write the report to this file}
        {--fail-on= : Exit with an error if any finding is at least this severity: low, medium, high or none}
        {--no-ai : Skip the AI review even if an API key is configured}
        {--no-sql : Skip the SQL preview}
        {--no-stats : Do not read table row counts from the database}
        {--show-sql : Print the SQL each migration would run (text format)}';

    protected $description = 'Review migrations for production risks (data loss, locks, unsafe deploys) before running them';

    public function handle(MigrationLocator $locator, MigrationReviewer $reviewer, DatabaseManager $db): int
    {
        $renderer = $this->renderer();
        $failOn = strtolower((string) ($this->option('fail-on') ?? config('migration-review.fail_on', 'none')));

        if ($renderer === null || ($failOn !== 'none' && Severity::tryFrom($failOn) === null)) {
            $this->components->error('Invalid --format or --fail-on value.');

            return self::INVALID;
        }

        $files = $this->files($locator);

        if ($files === []) {
            $this->components->info('No migrations to review.');

            return self::SUCCESS;
        }

        $database = $this->option('database');
        $connection = $db->connection(is_string($database) ? $database : null);
        $isText = $this->option('format') === 'text';
        $withAi = ! $this->option('no-ai');

        if ($isText && $withAi && ! $reviewer->hasAi() && config('migration-review.ai.enabled')) {
            $this->components->warn('AI review is off: set ANTHROPIC_API_KEY to enable it. Running static rules only.');
        }

        $reports = $reviewer->review(
            $files,
            $connection,
            withSql: ! $this->option('no-sql') && config('migration-review.sql_preview', true),
            withTableStats: ! $this->option('no-stats') && config('migration-review.table_stats', true),
            withAi: $withAi,
            progress: $isText && $withAi && $reviewer->hasAi()
                ? fn (MigrationFile $file) => $this->line("  <fg=gray>Reviewing {$file->name}…</>")
                : null,
        );

        $rendered = $renderer->render($reports);
        $this->output->write($rendered, false, $isText ? OutputInterface::OUTPUT_NORMAL : OutputInterface::OUTPUT_RAW);

        if (is_string($path = $this->option('output')) && $path !== '') {
            file_put_contents($path, $isText ? (new OutputFormatter(false))->format($rendered) : $rendered);
        }

        return $this->shouldFail($reports, $failOn) ? self::FAILURE : self::SUCCESS;
    }

    private function renderer(): ?Renderer
    {
        return match ($this->option('format')) {
            'text' => new TextRenderer((bool) $this->option('show-sql')),
            'json' => new JsonRenderer,
            'markdown', 'md' => new MarkdownRenderer,
            'github' => new GithubRenderer,
            default => null,
        };
    }

    /**
     * @return list<MigrationFile>
     */
    private function files(MigrationLocator $locator): array
    {
        $paths = array_values(array_map(strval(...), (array) $this->argument('paths')));

        if ($paths !== []) {
            return $locator->explicit($paths);
        }

        return $this->option('all')
            ? $locator->all($locator->defaultPaths())
            : $locator->pending($locator->defaultPaths());
    }

    /**
     * @param  list<MigrationReport>  $reports
     */
    private function shouldFail(array $reports, string $failOn): bool
    {
        $threshold = Severity::tryFrom($failOn);

        if ($threshold === null) {
            return false;
        }

        foreach ($reports as $report) {
            if ($report->highestSeverity()?->atLeast($threshold)) {
                return true;
            }
        }

        return false;
    }
}
