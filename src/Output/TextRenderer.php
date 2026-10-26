<?php

namespace GodfreysDragon\MigrationReview\Output;

use GodfreysDragon\MigrationReview\Analysis\Finding;
use GodfreysDragon\MigrationReview\Analysis\MigrationReport;
use GodfreysDragon\MigrationReview\Analysis\Severity;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * Human-readable console output using Symfony console style tags.
 */
class TextRenderer implements Renderer
{
    public function __construct(private readonly bool $showSql = false) {}

    public function render(array $reports): string
    {
        $out = [];

        foreach ($reports as $report) {
            $highest = $report->highestSeverity();
            $status = $highest === null ? '<fg=green;options=bold>PASS</>' : $this->badge($highest);

            $out[] = '';
            $out[] = " {$status} <options=bold>".$this->e($report->migration->name).'</>';
            $out[] = '      <fg=gray>'.$this->e($report->migration->relativePath()).'</>';

            if ($report->aiSummary !== null) {
                $out[] = '      <fg=cyan>AI:</> '.$this->e($report->aiSummary);
            }

            foreach ($report->sortedFindings() as $finding) {
                $out = [...$out, ...$this->finding($finding)];
            }

            if ($this->showSql && $report->sql !== []) {
                $out[] = '      <fg=gray>SQL:</>';
                foreach ($report->sql as $sql) {
                    $out[] = '        <fg=gray>'.$this->e($sql).';</>';
                }
            }

            foreach ($report->notes as $note) {
                $out[] = '      <fg=yellow>note:</> '.$this->e($note);
            }
        }

        $out[] = '';
        $out[] = ' '.$this->totals($reports);

        return implode(PHP_EOL, $out).PHP_EOL;
    }

    /**
     * @return list<string>
     */
    private function finding(Finding $finding): array
    {
        $location = $finding->line !== null ? "<fg=gray>:{$finding->line}</> " : '';
        $source = $finding->source === Finding::SOURCE_AI ? ' <fg=cyan>[ai]</>' : " <fg=gray>[{$finding->rule}]</>";

        $lines = [
            '',
            '      '.$this->badge($finding->severity).' '.$location.'<options=bold>'.$this->e($finding->title).'</>'.$source,
        ];

        if ($finding->message !== '') {
            $lines[] = '        '.$this->e($finding->message);
        }

        if ($finding->suggestion !== null) {
            $lines[] = '        <fg=green>→</> '.$this->e($finding->suggestion);
        }

        return $lines;
    }

    private function badge(Severity $severity): string
    {
        return match ($severity) {
            Severity::High => '<fg=white;bg=red;options=bold> HIGH </>',
            Severity::Medium => '<fg=black;bg=yellow;options=bold> MED  </>',
            Severity::Low => '<fg=black;bg=cyan> LOW  </>',
        };
    }

    /**
     * @param  list<MigrationReport>  $reports
     */
    private function totals(array $reports): string
    {
        $counts = ['high' => 0, 'medium' => 0, 'low' => 0];

        foreach ($reports as $report) {
            foreach ($report->findings as $finding) {
                $counts[$finding->severity->value]++;
            }
        }

        return sprintf(
            'Reviewed %d migration%s: <fg=red>%d high</>, <fg=yellow>%d medium</>, <fg=cyan>%d low</>.',
            count($reports),
            count($reports) === 1 ? '' : 's',
            $counts['high'],
            $counts['medium'],
            $counts['low'],
        );
    }

    private function e(string $text): string
    {
        return OutputFormatter::escape(ControlCharacters::strip($text));
    }
}
