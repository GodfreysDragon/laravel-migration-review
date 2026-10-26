<?php

namespace GodfreysDragon\MigrationReview\Ai;

use Illuminate\Contracts\Cache\Repository;

/**
 * Caches AI reviews keyed on everything the model sees, so re-running CI on an unchanged
 * migration costs nothing. Any change to the file, SQL, row counts, static findings, model
 * or prompt produces a new key.
 */
class CachingReviewer implements AiReviewer
{
    public function __construct(
        private readonly AiReviewer $inner,
        private readonly Repository $cache,
        private readonly PromptBuilder $prompts,
        private readonly int $ttlSeconds = 2_592_000,
    ) {}

    public function review(ReviewInput $input): AiReview
    {
        $key = 'migration-review:'.hash('sha256', implode("\0", [
            PromptBuilder::VERSION,
            $this->inner->fingerprint(),
            $this->prompts->user($input),
        ]));

        $cached = $this->cache->get($key);

        if (is_array($cached)) {
            return AiReview::fromArray($cached);
        }

        $review = $this->inner->review($input);
        $this->cache->put($key, $review->toArray(), $this->ttlSeconds);

        return $review;
    }

    public function fingerprint(): string
    {
        return $this->inner->fingerprint();
    }
}
