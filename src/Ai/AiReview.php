<?php

namespace GodfreysDragon\MigrationReview\Ai;

use GodfreysDragon\MigrationReview\Analysis\Finding;
use GodfreysDragon\MigrationReview\Analysis\Severity;

final class AiReview
{
    /**
     * @param  list<Finding>  $findings
     */
    public function __construct(
        public readonly string $summary,
        public readonly array $findings,
    ) {}

    /**
     * Build a review from the model's JSON, dropping malformed findings rather than failing the whole review.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $findings = [];

        foreach (is_array($data['findings'] ?? null) ? $data['findings'] : [] as $item) {
            if (! is_array($item)) {
                continue;
            }

            $severity = Severity::fromLoose(is_string($item['severity'] ?? null) ? $item['severity'] : null);
            $title = trim((string) ($item['title'] ?? ''));

            if ($severity === null || $title === '') {
                continue;
            }

            $findings[] = new Finding(
                rule: 'ai',
                severity: $severity,
                title: $title,
                message: trim((string) ($item['explanation'] ?? '')),
                line: is_numeric($item['line'] ?? null) && (int) $item['line'] > 0 ? (int) $item['line'] : null,
                suggestion: is_string($item['suggestion'] ?? null) && trim($item['suggestion']) !== '' ? trim($item['suggestion']) : null,
                table: is_string($item['table'] ?? null) && $item['table'] !== '' ? $item['table'] : null,
                source: Finding::SOURCE_AI,
            );
        }

        return new self(trim((string) ($data['summary'] ?? '')), $findings);
    }

    /**
     * @return array{summary: string, findings: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'summary' => $this->summary,
            'findings' => array_map(fn (Finding $f) => [
                'severity' => $f->severity->value,
                'title' => $f->title,
                'explanation' => $f->message,
                'line' => $f->line,
                'suggestion' => $f->suggestion,
                'table' => $f->table,
            ], $this->findings),
        ];
    }
}
