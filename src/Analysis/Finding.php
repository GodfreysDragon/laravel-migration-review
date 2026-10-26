<?php

namespace GodfreysDragon\MigrationReview\Analysis;

/**
 * A single problem found in a migration, either by a static rule or by the AI reviewer.
 */
final class Finding
{
    public const SOURCE_STATIC = 'static';

    public const SOURCE_AI = 'ai';

    public function __construct(
        public readonly string $rule,
        public Severity $severity,
        public readonly string $title,
        public readonly string $message,
        public readonly ?int $line = null,
        public readonly ?string $suggestion = null,
        public readonly ?string $table = null,
        public readonly string $source = self::SOURCE_STATIC,
    ) {}

    /**
     * @return array{rule: string, severity: string, title: string, message: string, line: int|null, suggestion: string|null, table: string|null, source: string}
     */
    public function toArray(): array
    {
        return [
            'rule' => $this->rule,
            'severity' => $this->severity->value,
            'title' => $this->title,
            'message' => $this->message,
            'line' => $this->line,
            'suggestion' => $this->suggestion,
            'table' => $this->table,
            'source' => $this->source,
        ];
    }
}
