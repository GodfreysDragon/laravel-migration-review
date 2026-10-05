<?php

namespace MigrationReview\Support\Parsed;

/**
 * One fluent chain on a Blueprint, flattened in source order.
 *
 * `$table->string('email', 100)->nullable()->change();` becomes the links
 * string('email', 100), nullable(), change().
 */
final class BlueprintCall
{
    /**
     * @param  list<array{method: string, args: list<mixed>}>  $links
     */
    public function __construct(
        public readonly array $links,
        public readonly int $line,
    ) {}

    public function method(): string
    {
        return $this->links[0]['method'];
    }

    /**
     * @return list<mixed>
     */
    public function args(): array
    {
        return $this->links[0]['args'];
    }

    public function has(string ...$methods): bool
    {
        foreach (array_slice($this->links, 1) as $link) {
            if (in_array($link['method'], $methods, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Column names referenced by the first link, e.g. 'email' or ['a', 'b'].
     *
     * @return list<string>
     */
    public function columns(): array
    {
        $first = $this->args()[0] ?? null;

        if (is_string($first)) {
            return [$first];
        }

        if (is_array($first)) {
            return array_values(array_filter($first, 'is_string'));
        }

        return [];
    }
}
