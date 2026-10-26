<?php

namespace GodfreysDragon\MigrationReview\Support\Parsed;

/**
 * A Schema facade call such as Schema::table('users', fn (Blueprint $table) => ...).
 */
final class SchemaOperation
{
    /**
     * @param  list<mixed>  $args  Literal arguments (non-literal arguments are null).
     * @param  list<BlueprintCall>  $blueprintCalls
     */
    public function __construct(
        public readonly string $method,
        public readonly ?string $table,
        public readonly array $args,
        public readonly int $line,
        public readonly array $blueprintCalls = [],
    ) {}

    public function isCreate(): bool
    {
        return $this->method === 'create';
    }

    public function altersExistingTable(): bool
    {
        return $this->method === 'table';
    }
}
