<?php

namespace MigrationReview\Tests\Unit;

use MigrationReview\Analysis\Finding;
use MigrationReview\Analysis\RuleContext;
use MigrationReview\Analysis\Rules;
use MigrationReview\Analysis\StaticAnalyzer;
use MigrationReview\Support\MigrationFile;
use MigrationReview\Support\MigrationParser;
use MigrationReview\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase as BaseTestCase;

class RulesTest extends BaseTestCase
{
    /**
     * @param  list<string>  $disabled
     * @return list<Finding>
     */
    private function analyze(MigrationFile $file, ?RuleContext $context = null, array $disabled = []): array
    {
        $analyzer = new StaticAnalyzer(new MigrationParser, [
            new Rules\DropTableRule,
            new Rules\DropColumnRule,
            new Rules\RenameRule,
            new Rules\ColumnChangeRule,
            new Rules\NotNullWithoutDefaultRule,
            new Rules\IndexOnExistingTableRule,
            new Rules\DataManipulationRule,
            new Rules\RawSqlRule,
            new Rules\MissingDownRule,
        ], $disabled);

        return $analyzer->analyze($analyzer->parse($file), $context ?? new RuleContext('pgsql'));
    }

    /**
     * @param  list<Finding>  $findings
     * @return list<string> "rule:severity@line"
     */
    private function summarize(array $findings): array
    {
        return array_map(fn (Finding $f) => "{$f->rule}:{$f->severity->value}@{$f->line}", $findings);
    }

    public function test_creating_a_new_table_is_clean(): void
    {
        $this->assertSame([], $this->analyze(TestCase::fixture('2026_01_01_000000_create_orders_table.php')));
    }

    public function test_adding_columns_and_an_index_to_an_existing_table(): void
    {
        $findings = $this->analyze(TestCase::fixture('2026_01_02_000000_add_status_to_orders_table.php'));

        $this->assertSame([
            'not-null-without-default:high@12',
            'index-on-existing-table:medium@15',
        ], $this->summarize($findings));
        $this->assertStringContainsString('CONCURRENTLY', $findings[1]->suggestion);
    }

    public function test_destructive_restructuring(): void
    {
        $findings = $this->analyze(TestCase::fixture('2026_01_03_000000_restructure_orders_table.php'));

        $this->assertSame([
            'drop-table:high@17',
            'drop-column:high@12',
            'rename:medium@13',
            'column-change:medium@14',
        ], $this->summarize($findings));
        $this->assertStringContainsString('drops attributes such as nullable', $findings[3]->message);
    }

    public function test_data_changes_raw_sql_models_and_ignore_comments(): void
    {
        $findings = $this->analyze(TestCase::fixture('2026_01_04_000000_backfill_order_totals.php'));

        // The second update on line 19 is silenced by the ignore comment above it.
        $this->assertSame([
            'data-manipulation:medium@11',
            'data-manipulation:medium@13',
            'raw-sql:high@15',
            'missing-down:low@7',
        ], $this->summarize($findings));
    }

    public function test_large_tables_escalate_lock_sensitive_findings(): void
    {
        $context = new RuleContext('pgsql', ['orders' => 5_000_000], largeTableRows: 1_000_000);
        $findings = $this->analyze(TestCase::fixture('2026_01_02_000000_add_status_to_orders_table.php'), $context);

        $this->assertSame('index-on-existing-table:high@15', $this->summarize($findings)[1]);
        $this->assertStringContainsString('about 5M rows', $findings[1]->message);
    }

    public function test_not_null_columns_are_a_softer_warning_on_mysql(): void
    {
        $findings = $this->analyze(TestCase::fixture('2026_01_02_000000_add_status_to_orders_table.php'), new RuleContext('mysql'));

        $this->assertSame('not-null-without-default:medium@12', $this->summarize($findings)[0]);
        $this->assertStringContainsString('ALGORITHM=INPLACE', $findings[1]->suggestion);
    }

    public function test_disabled_rules_do_not_run(): void
    {
        $findings = $this->analyze(TestCase::fixture('2026_01_04_000000_backfill_order_totals.php'), disabled: ['missing-down', 'data-manipulation', 'raw-sql']);

        $this->assertSame([], $findings);
    }

    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function snippets(): array
    {
        return [
            'nullable column is fine' => ["Schema::table('u', fn (Blueprint \$t) => \$t->string('a')->nullable());", []],
            'nullable(false) is not' => ["Schema::table('u', fn (Blueprint \$t) => \$t->string('a')->nullable(false));", ['not-null-without-default']],
            'foreignId constrained' => ["Schema::table('u', fn (Blueprint \$t) => \$t->foreignId('team_id')->constrained());", ['not-null-without-default', 'index-on-existing-table']],
            'nullable foreign key only indexes' => ["Schema::table('u', fn (Blueprint \$t) => \$t->foreignId('team_id')->nullable()->constrained());", ['index-on-existing-table']],
            'timestamps are nullable' => ["Schema::table('u', fn (Blueprint \$t) => \$t->timestamps());", []],
            'drop soft deletes' => ["Schema::table('u', fn (Blueprint \$t) => \$t->dropSoftDeletes());", ['drop-column']],
            'table rename' => ["Schema::rename('u', 'users');", ['rename']],
            'static dropColumns' => ["Schema::dropColumns('u', ['a']);", ['drop-column']],
            'truncate' => ["DB::table('u')->truncate();", ['data-manipulation']],
            'raw sql on existing table' => ["DB::statement('ALTER TABLE users ADD COLUMN x int');", ['raw-sql']],
            'blanket ignore' => ["// migration-review-ignore\n        Schema::drop('u');", []],
            'ignore other rule only' => ["// migration-review-ignore: rename\n        Schema::drop('u');", ['drop-table']],
        ];
    }

    /**
     * @param  list<string>  $expectedRules
     */
    #[DataProvider('snippets')]
    public function test_snippets(string $up, array $expectedRules): void
    {
        $rules = array_map(fn (Finding $f) => $f->rule, $this->analyze(TestCase::migration($up)));

        $this->assertSame($expectedRules, $rules);
    }

    public function test_raw_sql_severity_depends_on_what_it_touches(): void
    {
        $severities = fn (string $up) => array_map(
            fn (Finding $f) => $f->severity->value,
            $this->analyze(TestCase::migration($up)),
        );

        $created = "Schema::create('chunks', fn (Blueprint \$t) => \$t->id());\n        ";

        $this->assertSame(['low'], $severities("DB::statement('CREATE EXTENSION IF NOT EXISTS vector');"));
        $this->assertSame(['low'], $severities($created."DB::statement('CREATE INDEX idx ON chunks USING gin (body)');"));
        $this->assertSame(['medium'], $severities($created."DB::statement('CREATE INDEX idx ON users (email)');"));
        $this->assertSame(['medium'], $severities($created.'DB::statement($sql);'));
        $this->assertSame(['high'], $severities("DB::unprepared('DELETE FROM sessions');"));
        $this->assertSame(['medium'], $severities("DB::statement('DELETE FROM sessions WHERE expired = 1');"));
    }

    public function test_missing_down_method(): void
    {
        $findings = $this->analyze(TestCase::migration("Schema::create('x', fn (Blueprint \$t) => \$t->id());", down: null));

        $this->assertSame(['missing-down'], array_map(fn (Finding $f) => $f->rule, $findings));
        $this->assertSame('No down() method', $findings[0]->title);
    }
}
