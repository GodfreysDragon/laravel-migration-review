<?php

namespace MigrationReview\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use MigrationReview\Ai\AiReview;
use MigrationReview\Ai\AiReviewer;
use MigrationReview\Analysis\Finding;
use MigrationReview\Analysis\Severity;
use MigrationReview\Tests\Fakes\FakeAiReviewer;
use MigrationReview\Tests\TestCase;

class ReviewCommandTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function runJson(array $parameters = []): array
    {
        $exitCode = Artisan::call('migrate:review', ['--format' => 'json', ...$parameters]);
        $data = json_decode(Artisan::output(), true);

        $this->assertIsArray($data, Artisan::output());
        $data['exit'] = $exitCode;

        return $data;
    }

    public function test_it_reviews_explicit_paths_and_previews_sql(): void
    {
        $data = $this->runJson(['paths' => [self::fixturePath('2026_01_01_000000_create_orders_table.php')]]);

        $this->assertSame(0, $data['exit']);
        $migration = $data['migrations'][0];
        $this->assertSame('2026_01_01_000000_create_orders_table', $migration['migration']);
        $this->assertSame([], $migration['findings']);
        $this->assertStringContainsString('create table "orders"', $migration['sql'][0]);
    }

    public function test_only_pending_migrations_are_reviewed_by_default(): void
    {
        $this->app['migrator']->path(self::fixturePath());
        $this->artisan('migrate', ['--path' => self::fixturePath('2026_01_01_000000_create_orders_table.php'), '--realpath' => true])->assertSuccessful();

        $data = $this->runJson();

        $this->assertSame([
            '2026_01_02_000000_add_status_to_orders_table',
            '2026_01_03_000000_restructure_orders_table',
            '2026_01_04_000000_backfill_order_totals',
        ], array_column($data['migrations'], 'migration'));
    }

    public function test_tables_created_by_an_earlier_pending_migration_are_not_flagged(): void
    {
        $this->app['migrator']->path(self::fixturePath());

        $migrations = array_column($this->runJson()['migrations'], 'findings', 'migration');

        $this->assertSame([], $migrations['2026_01_02_000000_add_status_to_orders_table']);
        // legacy_invoices is not created in this run; ->change() still drops unrestated modifiers on an empty table.
        $this->assertSame(['drop-table', 'column-change'], array_column($migrations['2026_01_03_000000_restructure_orders_table'], 'rule'));
    }

    public function test_all_option_includes_migrations_that_already_ran(): void
    {
        $this->app['migrator']->path(self::fixturePath());
        $this->artisan('migrate', ['--path' => self::fixturePath('2026_01_01_000000_create_orders_table.php'), '--realpath' => true])->assertSuccessful();

        $this->assertCount(4, $this->runJson(['--all' => true])['migrations']);
    }

    public function test_table_sizes_from_the_database_escalate_findings(): void
    {
        $this->artisan('migrate', ['--path' => self::fixturePath('2026_01_01_000000_create_orders_table.php'), '--realpath' => true])->assertSuccessful();
        DB::table('orders')->insert(array_map(fn ($i) => ['reference' => "R{$i}", 'customer_email' => "c{$i}@x.test", 'total_cents' => $i], range(1, 5)));
        config(['migration-review.large_table_rows' => 5]);

        $findings = $this->runJson(['paths' => [self::fixturePath('2026_01_02_000000_add_status_to_orders_table.php')]])['migrations'][0]['findings'];
        $index = array_values(array_filter($findings, fn ($f) => $f['rule'] === 'index-on-existing-table'))[0];

        $this->assertSame('high', $index['severity']);
        $this->assertStringContainsString('`orders` currently has about 5 rows', $index['message']);
    }

    public function test_fail_on_controls_the_exit_code(): void
    {
        $path = self::fixturePath('2026_01_03_000000_restructure_orders_table.php');

        $this->artisan('migrate:review', ['paths' => [$path], '--fail-on' => 'high'])->assertFailed();
        $this->artisan('migrate:review', ['paths' => [$path], '--fail-on' => 'none'])->assertSuccessful();
        $this->artisan('migrate:review', ['paths' => [$path], '--fail-on' => 'nope'])->assertExitCode(2);
    }

    public function test_sql_preview_failures_become_notes(): void
    {
        // The backfill migration references App\Models\Order, which does not exist here.
        $data = $this->runJson(['paths' => [self::fixturePath('2026_01_04_000000_backfill_order_totals.php')]]);

        $this->assertStringStartsWith('SQL preview unavailable:', $data['migrations'][0]['notes'][0]);
        $this->assertNotEmpty($data['migrations'][0]['findings']);
    }

    public function test_previews_built_from_schema_reads_are_marked_approximate(): void
    {
        $this->artisan('migrate', ['--path' => self::fixturePath('2026_01_01_000000_create_orders_table.php'), '--realpath' => true])->assertSuccessful();
        config(['migration-review.ai.api_key' => 'test-key']);
        $fake = new FakeAiReviewer(new AiReview('', []));
        $this->app->instance(AiReviewer::class, $fake);

        // On SQLite, ->change() rebuilds the table from the live column list, which is empty in pretend mode.
        $migration = $this->runJson(['paths' => [self::fixturePath('2026_01_03_000000_restructure_orders_table.php')]])['migrations'][0];

        $this->assertContains('create table "__temp__orders" ()', $migration['sql']);
        $this->assertStringStartsWith('SQL preview is approximate:', $migration['notes'][0]);
        $this->assertTrue($fake->inputs[0]->sqlApproximate);
    }

    public function test_the_ai_gets_row_counts_for_tables_touched_by_query_builder_writes(): void
    {
        $this->artisan('migrate', ['--path' => self::fixturePath('2026_01_01_000000_create_orders_table.php'), '--realpath' => true])->assertSuccessful();
        DB::table('orders')->insert(['reference' => 'R1', 'customer_email' => 'c1@x.test', 'total_cents' => 1]);
        config(['migration-review.ai.api_key' => 'test-key']);
        $fake = new FakeAiReviewer(new AiReview('', []));
        $this->app->instance(AiReviewer::class, $fake);

        // The backfill fixture touches `orders` only through DB::table(), never through Schema::.
        $this->runJson(['paths' => [self::fixturePath('2026_01_04_000000_backfill_order_totals.php')]]);

        $this->assertSame(['orders' => 1], $fake->inputs[0]->tableRows);
    }

    public function test_ai_findings_are_merged_when_a_reviewer_is_configured(): void
    {
        config(['migration-review.ai.api_key' => 'test-key']);
        $fake = new FakeAiReviewer(new AiReview('Safe apart from the lock.', [
            new Finding('ai', Severity::Medium, 'status needs a CHECK constraint', 'Free-form strings drift.', 12, source: Finding::SOURCE_AI),
        ]));
        $this->app->instance(AiReviewer::class, $fake);

        $data = $this->runJson(['paths' => [self::fixturePath('2026_01_02_000000_add_status_to_orders_table.php')]]);
        $migration = $data['migrations'][0];

        $this->assertSame(1, $fake->calls);
        $this->assertSame('Safe apart from the lock.', $migration['ai_summary']);
        $this->assertContains('status needs a CHECK constraint', array_column($migration['findings'], 'title'));
        $this->assertCount(2, $fake->inputs[0]->staticFindings);
        $this->assertNotEmpty($fake->inputs[0]->sql);
    }

    public function test_no_ai_option_and_ai_failures(): void
    {
        config(['migration-review.ai.api_key' => 'test-key']);
        $fake = new FakeAiReviewer(failure: 'Anthropic rate limit reached; AI review skipped.');
        $this->app->instance(AiReviewer::class, $fake);
        $path = self::fixturePath('2026_01_01_000000_create_orders_table.php');

        $this->runJson(['paths' => [$path], '--no-ai' => true]);
        $this->assertSame(0, $fake->calls);

        $data = $this->runJson(['paths' => [$path]]);
        $this->assertSame(1, $fake->calls);
        $this->assertSame(['Anthropic rate limit reached; AI review skipped.'], $data['migrations'][0]['notes']);
        $this->assertSame(0, $data['exit']);
    }

    public function test_text_output_and_report_file(): void
    {
        $file = sys_get_temp_dir().'/migration-review-'.uniqid().'.txt';

        $this->artisan('migrate:review', ['paths' => [self::fixturePath()], '--output' => $file])
            ->expectsOutputToContain('AI review is off')
            ->expectsOutputToContain('Reviewed 4 migrations')
            ->assertSuccessful();

        $contents = (string) file_get_contents($file);
        @unlink($file);

        $this->assertStringContainsString('Drops table `legacy_invoices`', $contents);
        $this->assertStringNotContainsString('<fg=', $contents);
    }

    public function test_github_format(): void
    {
        Artisan::call('migrate:review', ['paths' => [self::fixturePath('2026_01_03_000000_restructure_orders_table.php')], '--format' => 'github']);

        $this->assertStringContainsString('fixtures/migrations/2026_01_03_000000_restructure_orders_table.php,line=17,', Artisan::output());
    }

    public function test_up_runs_only_when_the_sql_preview_is_on(): void
    {
        $dir = sys_get_temp_dir().'/migration-review-'.uniqid();
        mkdir($dir);
        $marker = $dir.'/up-ran.txt';
        file_put_contents($dir.'/2026_02_01_000000_touch_marker.php', '<?php return new class extends Illuminate\Database\Migrations\Migration {'
            .' public function up(): void { file_put_contents('.var_export($marker, true).", 'ran'); } };");

        try {
            $this->runJson(['paths' => [$dir], '--no-sql' => true]);
            $this->assertFileDoesNotExist($marker);

            config(['migration-review.sql_preview' => false]);
            $this->runJson(['paths' => [$dir]]);
            $this->assertFileDoesNotExist($marker);

            // Documented behaviour: pretend mode captures queries, but other code in up() runs.
            config(['migration-review.sql_preview' => true]);
            $this->runJson(['paths' => [$dir]]);
            $this->assertFileExists($marker);
        } finally {
            array_map('unlink', glob($dir.'/*') ?: []);
            rmdir($dir);
        }
    }

    public function test_nothing_to_review(): void
    {
        $this->artisan('migrate:review')->expectsOutputToContain('No migrations to review')->assertSuccessful();
    }
}
