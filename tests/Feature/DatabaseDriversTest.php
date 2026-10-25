<?php

namespace MigrationReview\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MigrationReview\Support\TableStats;
use MigrationReview\Tests\TestCase;

/**
 * Runs against a real MySQL or PostgreSQL server, which CI provides as a service container.
 *
 * Skipped unless TEST_DB_DRIVER is set to "mysql" or "pgsql"; the connection is read from
 * DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME and DB_PASSWORD.
 */
class DatabaseDriversTest extends TestCase
{
    private string $driver = '';

    protected function setUp(): void
    {
        $this->driver = (string) getenv('TEST_DB_DRIVER');

        if (! in_array($this->driver, ['mysql', 'pgsql'], true)) {
            $this->markTestSkipped('Set TEST_DB_DRIVER=mysql or pgsql to run against a real database.');
        }

        parent::setUp();

        Schema::dropAllTables();
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('database.connections.testing', [
            'driver' => $this->driver,
            'host' => getenv('DB_HOST') ?: '127.0.0.1',
            'port' => getenv('DB_PORT') ?: ($this->driver === 'pgsql' ? '5432' : '3306'),
            'database' => getenv('DB_DATABASE') ?: 'testing',
            'username' => getenv('DB_USERNAME') ?: ($this->driver === 'pgsql' ? 'postgres' : 'root'),
            'password' => getenv('DB_PASSWORD') ?: '',
            'charset' => $this->driver === 'pgsql' ? 'utf8' : 'utf8mb4',
            'prefix' => '',
        ]);
    }

    private function seedOrders(int $count): void
    {
        $this->artisan('migrate', ['--path' => self::fixturePath('2026_01_01_000000_create_orders_table.php'), '--realpath' => true])->assertSuccessful();

        foreach (array_chunk(range(1, $count), 500) as $chunk) {
            DB::table('orders')->insert(array_map(fn ($i) => ['reference' => "R{$i}", 'customer_email' => "c{$i}@x.test", 'total_cents' => $i], $chunk));
        }

        // Row estimates come from planner statistics, which are refreshed by ANALYZE.
        DB::statement($this->driver === 'pgsql' ? 'analyze orders' : 'analyze table orders');
    }

    /**
     * @return array<string, mixed>
     */
    private function review(array $parameters): array
    {
        Artisan::call('migrate:review', ['--format' => 'json', '--no-ai' => true, ...$parameters]);
        $data = json_decode(Artisan::output(), true);
        $this->assertIsArray($data, Artisan::output());

        return $data;
    }

    public function test_table_stats_reads_row_estimates_from_the_catalog(): void
    {
        $this->seedOrders(1500);

        $rows = (new TableStats)->rows(DB::connection(), ['orders', 'missing_table']);

        $this->assertSame(['orders'], array_keys($rows));
        $this->assertGreaterThan(1000, $rows['orders']);
        $this->assertLessThan(2000, $rows['orders']);
    }

    public function test_findings_use_the_driver_and_table_size(): void
    {
        $this->seedOrders(1500);
        config(['migration-review.large_table_rows' => 1000]);

        $migration = $this->review(['paths' => [self::fixturePath('2026_01_02_000000_add_status_to_orders_table.php')]])['migrations'][0];
        $findings = array_column($migration['findings'], null, 'rule');

        $this->assertSame($this->driver === 'pgsql' ? 'high' : 'medium', $findings['not-null-without-default']['severity']);
        $this->assertSame('high', $findings['index-on-existing-table']['severity']);
        $this->assertStringContainsString($this->driver === 'pgsql' ? 'CONCURRENTLY' : 'ALGORITHM=INPLACE', $findings['index-on-existing-table']['suggestion']);
        $this->assertStringContainsString('about 1.5K rows', $findings['index-on-existing-table']['message']);
        $this->assertStringContainsStringIgnoringCase('alter table', $migration['sql'][0]);
        $this->assertSame([], $migration['notes']);
    }

    public function test_pending_run_on_an_empty_database(): void
    {
        $this->app['migrator']->path(self::fixturePath());

        $migrations = array_column($this->review([])['migrations'], null, 'migration');

        $this->assertCount(4, $migrations);
        $this->assertSame([], $migrations['2026_01_02_000000_add_status_to_orders_table']['findings']);
        $this->assertNotEmpty($migrations['2026_01_02_000000_add_status_to_orders_table']['sql']);
    }

    public function test_column_change_previews_without_touching_the_schema(): void
    {
        $this->seedOrders(10);

        $migration = $this->review(['paths' => [self::fixturePath('2026_01_03_000000_restructure_orders_table.php')]])['migrations'][0];

        $this->assertSame([], $migration['notes']);
        $this->assertNotEmpty($migration['sql']);
        $this->assertTrue(Schema::hasColumn('orders', 'notes'), 'The SQL preview must not run the migration.');
        $this->assertTrue(Schema::hasColumn('orders', 'customer_email'));
    }
}
