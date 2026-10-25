<?php

namespace MigrationReview\Tests\Unit;

use MigrationReview\Analysis\Finding;
use MigrationReview\Analysis\MigrationReport;
use MigrationReview\Analysis\Severity;
use MigrationReview\Output\GithubRenderer;
use MigrationReview\Output\JsonRenderer;
use MigrationReview\Output\MarkdownRenderer;
use MigrationReview\Output\TextRenderer;
use MigrationReview\Tests\TestCase;
use PHPUnit\Framework\TestCase as BaseTestCase;
use Symfony\Component\Console\Formatter\OutputFormatter;

class RendererTest extends BaseTestCase
{
    /**
     * @return list<MigrationReport>
     */
    private function reports(): array
    {
        $file = TestCase::migration("Schema::drop('users');");

        return [
            new MigrationReport($file, [
                new Finding('missing-down', Severity::Low, 'No down()', 'Cannot roll back.', 7),
                new Finding('drop-table', Severity::High, 'Drops table `users`', "Data loss: 100%, gone\nforever.", 12, 'Back up first.', 'users'),
                new Finding('ai', Severity::Medium, 'Check | pipes', 'From the model.', null, null, null, Finding::SOURCE_AI),
            ], aiSummary: 'Dangerous.', notes: ['SQL preview unavailable: boom']),
        ];
    }

    public function test_github_annotations_are_escaped_and_mapped_by_severity(): void
    {
        $lines = explode("\n", trim((new GithubRenderer)->render($this->reports())));

        $this->assertSame(
            '::error file=database/migrations/2026_02_01_000000_test.php,line=12,title=Migration review%3A Drops table `users`::Data loss: 100%25, gone%0Aforever.%0A%0ASuggestion: Back up first.',
            $lines[0],
        );
        $this->assertStringStartsWith('::warning file=database/migrations/2026_02_01_000000_test.php,title=', $lines[1]);
        $this->assertStringStartsWith('::notice ', $lines[2]);
    }

    public function test_markdown_is_a_pr_ready_table(): void
    {
        $markdown = (new MarkdownRenderer)->render($this->reports());

        $this->assertStringContainsString('### 🔴 `2026_02_01_000000_test`', $markdown);
        $this->assertStringContainsString('> Dangerous.', $markdown);
        $this->assertStringContainsString('| HIGH | 12 | **Drops table `users`**', $markdown);
        $this->assertStringContainsString('**Check \| pipes** _(AI)_', $markdown);
        $this->assertStringContainsString('3 findings across 1 migration', $markdown);
    }

    public function test_json_is_sorted_by_severity(): void
    {
        $data = json_decode((new JsonRenderer)->render($this->reports()), true);

        $this->assertSame('high', $data['migrations'][0]['highest_severity']);
        $this->assertSame(['high', 'medium', 'low'], array_column($data['migrations'][0]['findings'], 'severity'));
    }

    public function test_text_output_renders_without_markup_errors(): void
    {
        $text = (new OutputFormatter(false))->format((new TextRenderer)->render($this->reports()));

        $this->assertStringContainsString('HIGH  :12 Drops table `users` [drop-table]', $text);
        $this->assertStringContainsString('AI: Dangerous.', $text);
        $this->assertStringContainsString('note: SQL preview unavailable: boom', $text);
        $this->assertStringContainsString('Reviewed 1 migration: 1 high, 1 medium, 1 low.', $text);
    }

    public function test_markdown_keeps_notes_when_there_are_no_findings(): void
    {
        $report = new MigrationReport(TestCase::migration("Schema::create('x', fn (\$t) => \$t->id());"), notes: ['AI review failed: boom']);
        $markdown = (new MarkdownRenderer)->render([$report]);

        $this->assertStringContainsString("No issues found.\n\n_Note: AI review failed: boom_", $markdown);
    }
}
