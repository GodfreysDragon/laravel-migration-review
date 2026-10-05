<?php

namespace MigrationReview\Support\Parsed;

/**
 * A non-Schema call inside up() that a rule may care about: raw SQL, query builder writes, model usage.
 */
final class CodeCall
{
    public const RAW_SQL = 'raw_sql';

    public const QUERY_WRITE = 'query_write';

    public const MODEL = 'model';

    /**
     * @param  string  $kind  One of the class constants.
     * @param  string  $target  e.g. "DB::statement", "users->update", "App\Models\User::query".
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $target,
        public readonly int $line,
        public readonly ?string $table = null,
        public readonly ?string $sql = null,
    ) {}
}
