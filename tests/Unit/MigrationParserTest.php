<?php

namespace GodfreysDragon\MigrationReview\Tests\Unit;

use GodfreysDragon\MigrationReview\Support\MigrationParser;
use GodfreysDragon\MigrationReview\Support\Parsed\CodeCall;
use GodfreysDragon\MigrationReview\Tests\TestCase;
use PHPUnit\Framework\TestCase as BaseTestCase;

class MigrationParserTest extends BaseTestCase
{
    public function test_it_extracts_schema_operations_and_flattens_blueprint_chains(): void
    {
        $parsed = (new MigrationParser)->parse(TestCase::fixture('2026_01_02_000000_add_status_to_orders_table.php'));

        $this->assertCount(1, $parsed->operations);
        $operation = $parsed->operations[0];
        $this->assertSame('table', $operation->method);
        $this->assertSame('orders', $operation->table);
        $this->assertSame(11, $operation->line);

        $calls = $operation->blueprintCalls;
        $this->assertSame(['string', 'string', 'timestamp', 'index'], array_map(fn ($c) => $c->method(), $calls));
        $this->assertSame(['currency', 3], $calls[1]->args());
        $this->assertTrue($calls[1]->has('default'));
        $this->assertSame([['method' => 'default', 'args' => ['USD']]], array_slice($calls[1]->links, 1));
        $this->assertSame(['customer_email'], $calls[3]->columns());
        $this->assertSame(1, $parsed->downStatementCount);
    }

    public function test_it_finds_raw_sql_query_writes_and_model_usage(): void
    {
        $parsed = (new MigrationParser)->parse(TestCase::fixture('2026_01_04_000000_backfill_order_totals.php'));

        $this->assertSame(
            [
                [CodeCall::QUERY_WRITE, 'orders->update'],
                [CodeCall::MODEL, 'App\Models\Order::query'],
                [CodeCall::RAW_SQL, 'DB::statement'],
                [CodeCall::QUERY_WRITE, 'orders->update'],
            ],
            array_map(fn (CodeCall $c) => [$c->kind, $c->target], $parsed->codeCalls),
        );
        $this->assertSame('DELETE FROM order_events', $parsed->codeCalls[2]->sql);
        $this->assertSame(0, $parsed->downStatementCount);
    }

    public function test_it_handles_arrow_functions_and_schema_connection_calls(): void
    {
        $parsed = (new MigrationParser)->parse(TestCase::migration(
            "Schema::connection('pgsql')->table('users', fn (Blueprint \$t) => \$t->dropColumn(['a', 'b']));",
        ));

        $this->assertSame('table', $parsed->operations[0]->method);
        $this->assertSame('users', $parsed->operations[0]->table);
        $this->assertSame(['a', 'b'], $parsed->operations[0]->blueprintCalls[0]->columns());
    }

    public function test_it_reports_parse_errors_instead_of_throwing(): void
    {
        $parsed = (new MigrationParser)->parse(TestCase::migration('Schema::table(\'x\', function ('));

        $this->assertNotNull($parsed->parseError);
    }

    public function test_it_tracks_tables_created_in_the_same_migration(): void
    {
        $parsed = (new MigrationParser)->parse(TestCase::fixture('2026_01_01_000000_create_orders_table.php'));

        $this->assertSame(['orders'], $parsed->createdTables());
        $this->assertTrue($parsed->createsTable('orders'));
    }
}
