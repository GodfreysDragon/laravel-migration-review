<?php

namespace MigrationReview;

use Anthropic\Client;
use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use MigrationReview\Ai\AiReviewer;
use MigrationReview\Ai\CachingReviewer;
use MigrationReview\Ai\ClaudeReviewer;
use MigrationReview\Ai\PromptBuilder;
use MigrationReview\Analysis\Rules\Rule;
use MigrationReview\Analysis\StaticAnalyzer;
use MigrationReview\Commands\ReviewMigrationsCommand;
use MigrationReview\Support\MigrationLocator;
use MigrationReview\Support\MigrationParser;
use MigrationReview\Support\SqlPreviewer;
use MigrationReview\Support\TableStats;

class MigrationReviewServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/migration-review.php', 'migration-review');

        $this->app->singleton(StaticAnalyzer::class, fn (Application $app) => new StaticAnalyzer(
            new MigrationParser,
            array_values(array_map(fn (string $rule): Rule => $app->make($rule), $app['config']->get('migration-review.rules', []))),
            $app['config']->get('migration-review.disabled_rules', []),
        ));

        $this->app->singleton(SqlPreviewer::class, fn (Application $app) => new SqlPreviewer($app['db']));

        $this->app->singleton(MigrationLocator::class, fn (Application $app) => new MigrationLocator($app['migrator'], $app->basePath()));

        $this->app->bind(AiReviewer::class, function (Application $app) {
            $config = $app['config']->get('migration-review.ai');
            $prompts = new PromptBuilder;

            $reviewer = new ClaudeReviewer(
                new Client(apiKey: $config['api_key'], requestOptions: ['timeout' => (float) ($config['timeout'] ?? 120)]),
                $prompts,
                $config['model'],
                $config['effort'],
                (int) $config['max_tokens'],
            );

            if (! ($config['cache']['enabled'] ?? false)) {
                return $reviewer;
            }

            return new CachingReviewer(
                $reviewer,
                $app['cache']->store($config['cache']['store'] ?? null),
                $prompts,
                (int) $config['cache']['ttl'],
            );
        });

        $this->app->bind(MigrationReviewer::class, fn (Application $app) => new MigrationReviewer(
            $app->make(StaticAnalyzer::class),
            $app->make(SqlPreviewer::class),
            $app->make(TableStats::class),
            $this->aiAvailable($app) ? $app->make(AiReviewer::class) : null,
            (int) $app['config']->get('migration-review.large_table_rows', 1_000_000),
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/migration-review.php' => config_path('migration-review.php'),
            ], 'migration-review-config');

            $this->commands([ReviewMigrationsCommand::class]);
        }
    }

    private function aiAvailable(Application $app): bool
    {
        return (bool) $app['config']->get('migration-review.ai.enabled')
            && filled($app['config']->get('migration-review.ai.api_key'));
    }
}
