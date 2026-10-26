<?php

namespace GodfreysDragon\MigrationReview\Tests\Unit;

use GodfreysDragon\MigrationReview\Analysis\Finding;
use GodfreysDragon\MigrationReview\Analysis\MigrationReport;
use GodfreysDragon\MigrationReview\Analysis\Severity;
use GodfreysDragon\MigrationReview\Output\GithubRenderer;
use GodfreysDragon\MigrationReview\Output\JsonRenderer;
use GodfreysDragon\MigrationReview\Output\MarkdownRenderer;
use GodfreysDragon\MigrationReview\Output\TextRenderer;
use GodfreysDragon\MigrationReview\Tests\TestCase;
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

    public function test_markdown_neutralises_links_html_and_mentions_from_untrusted_text(): void
    {
        $report = new MigrationReport(TestCase::migration("Schema::drop('users');"), [
            new Finding('ai', Severity::High, 'Click [here](https://evil.example) @octocat', 'See ![x](https://evil.example/p.png) <img src=x> *bold*', 3, 'Keep `a|b` and `@team`', null, Finding::SOURCE_AI),
        ], aiSummary: "Ping @org/team\nand [link](https://evil.example)", notes: ["Failed: <b>x</b> _y_ \x1b[31mred"]);

        $markdown = (new MarkdownRenderer)->render([$report]);

        $this->assertStringContainsString("Click \\[here\\](https://evil.example) @\u{200B}octocat", $markdown);
        $this->assertStringContainsString('See \!\[x\](https://evil.example/p.png) &lt;img src=x&gt; \*bold\*', $markdown);
        $this->assertStringContainsString('Keep `a\|b` and `@team`', $markdown);
        $this->assertStringContainsString("> Ping @\u{200B}org/team and \\[link\\](https://evil.example)", $markdown);
        $this->assertStringContainsString('_Note: Failed: &lt;b&gt;x&lt;/b&gt; \_y\_ \[31mred_', $markdown);
        $this->assertStringNotContainsString("\x1b", $markdown);
    }

    public function test_control_characters_never_reach_the_terminal_or_annotations(): void
    {
        $reports = [new MigrationReport(TestCase::migration("Schema::drop('users');"), [
            new Finding('ai', Severity::High, "Title \x1b]8;;https://evil.example\x07link", "Body \x1b[2J\u{9B}\xFF cleared", 3, null, null, Finding::SOURCE_AI),
        ])];

        $text = (new TextRenderer)->render($reports);
        $github = (new GithubRenderer)->render($reports);

        $this->assertStringNotContainsString("\x1b", $text);
        $this->assertStringNotContainsString("\x07", $text);
        $this->assertStringNotContainsString("\x1b", $github);
        $this->assertStringContainsString('Body [2J? cleared', $github);
        $this->assertTrue(mb_check_encoding($text.$github, 'UTF-8'));
    }

    public function test_markdown_keeps_notes_when_there_are_no_findings(): void
    {
        $report = new MigrationReport(TestCase::migration("Schema::create('x', fn (\$t) => \$t->id());"), notes: ['AI review failed: boom']);
        $markdown = (new MarkdownRenderer)->render([$report]);

        $this->assertStringContainsString("No issues found.\n\n_Note: AI review failed: boom_", $markdown);
    }
}
