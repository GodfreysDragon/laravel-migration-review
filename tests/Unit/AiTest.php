<?php

namespace MigrationReview\Tests\Unit;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use MigrationReview\Ai\AiReview;
use MigrationReview\Ai\CachingReviewer;
use MigrationReview\Ai\PromptBuilder;
use MigrationReview\Ai\ReviewInput;
use MigrationReview\Analysis\Finding;
use MigrationReview\Analysis\Severity;
use MigrationReview\Tests\Fakes\FakeAiReviewer;
use MigrationReview\Tests\TestCase;
use PHPUnit\Framework\TestCase as BaseTestCase;

class AiTest extends BaseTestCase
{
    public function test_review_parsing_keeps_valid_findings_and_drops_malformed_ones(): void
    {
        $review = AiReview::fromArray([
            'summary' => ' Risky on large tables. ',
            'findings' => [
                ['severity' => 'HIGH', 'title' => 'Lock on orders', 'explanation' => 'Long lock.', 'line' => 12, 'suggestion' => 'Batch it', 'table' => 'orders'],
                ['severity' => 'catastrophic', 'title' => 'Unknown severity'],
                ['severity' => 'low', 'title' => ''],
                'not an object',
                ['severity' => 'low', 'title' => 'No line', 'explanation' => 'x', 'line' => null, 'suggestion' => '', 'table' => null],
            ],
        ]);

        $this->assertSame('Risky on large tables.', $review->summary);
        $this->assertCount(2, $review->findings);
        $this->assertSame(Severity::High, $review->findings[0]->severity);
        $this->assertSame(Finding::SOURCE_AI, $review->findings[0]->source);
        $this->assertSame(12, $review->findings[0]->line);
        $this->assertNull($review->findings[1]->line);
        $this->assertNull($review->findings[1]->suggestion);
    }

    public function test_prompt_contains_numbered_source_sql_sizes_and_static_findings(): void
    {
        $input = new ReviewInput(
            migration: TestCase::fixture('2026_01_02_000000_add_status_to_orders_table.php'),
            driver: 'pgsql',
            sql: ['alter table "orders" add column "status" varchar(255) not null'],
            tableRows: ['orders' => 42],
            staticFindings: [new Finding('drop-column', Severity::High, 'Drops `notes`', 'msg', 12)],
        );

        $prompt = (new PromptBuilder)->user($input);

        $this->assertStringContainsString('<database_driver>pgsql</database_driver>', $prompt);
        $this->assertStringContainsString('12 |             $table->string(\'status\');', $prompt);
        $this->assertStringContainsString('- orders: ~42 rows', $prompt);
        $this->assertStringContainsString('add column "status" varchar(255) not null;', $prompt);
        $this->assertStringContainsString('- [high] line 12, drop-column: Drops `notes`', $prompt);
        $this->assertStringContainsString('path="tests/fixtures/migrations/2026_01_02_000000_add_status_to_orders_table.php"', $prompt);
    }

    public function test_schema_is_strict(): void
    {
        $schema = (new PromptBuilder)->schema();

        $this->assertFalse($schema['additionalProperties']);
        $this->assertFalse($schema['properties']['findings']['items']['additionalProperties']);
        $this->assertSame(
            array_keys($schema['properties']['findings']['items']['properties']),
            $schema['properties']['findings']['items']['required'],
        );
    }

    public function test_caching_reviewer_only_calls_the_model_once_per_unchanged_input(): void
    {
        $fake = new FakeAiReviewer(new AiReview('ok', [new Finding('ai', Severity::Medium, 'From AI', 'm', 3, source: Finding::SOURCE_AI)]));
        $cache = new Repository(new ArrayStore);
        $reviewer = new CachingReviewer($fake, $cache, new PromptBuilder);
        $input = new ReviewInput(TestCase::fixture('2026_01_01_000000_create_orders_table.php'), 'sqlite');

        $first = $reviewer->review($input);
        $second = $reviewer->review($input);
        $reviewer->review(new ReviewInput(TestCase::fixture('2026_01_01_000000_create_orders_table.php'), 'mysql'));

        $this->assertSame(2, $fake->calls);
        $this->assertSame('From AI', $second->findings[0]->title);
        $this->assertEquals($first->toArray(), $second->toArray());
    }
}
