<?php

namespace MigrationReview\Output;

use MigrationReview\Analysis\Severity;

/**
 * GitHub Actions workflow commands, which show up as inline annotations on the pull request diff.
 *
 * @see https://docs.github.com/actions/reference/workflow-commands-for-github-actions
 */
class GithubRenderer implements Renderer
{
    public function render(array $reports): string
    {
        $out = [];

        foreach ($reports as $report) {
            foreach ($report->sortedFindings() as $finding) {
                $command = match ($finding->severity) {
                    Severity::High => 'error',
                    Severity::Medium => 'warning',
                    Severity::Low => 'notice',
                };

                $properties = ['file='.$this->property($report->migration->relativePath())];

                if ($finding->line !== null) {
                    $properties[] = 'line='.$finding->line;
                }

                $properties[] = 'title='.$this->property('Migration review: '.$finding->title);

                $message = $finding->message.($finding->suggestion !== null ? "\n\nSuggestion: ".$finding->suggestion : '');

                $out[] = "::{$command} ".implode(',', $properties).'::'.$this->data($message);
            }
        }

        return $out === [] ? '' : implode("\n", $out)."\n";
    }

    private function data(string $value): string
    {
        return str_replace(['%', "\r", "\n"], ['%25', '%0D', '%0A'], $value);
    }

    private function property(string $value): string
    {
        return str_replace([':', ','], ['%3A', '%2C'], $this->data($value));
    }
}
