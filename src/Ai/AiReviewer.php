<?php

namespace GodfreysDragon\MigrationReview\Ai;

interface AiReviewer
{
    /**
     * @throws AiReviewFailed
     */
    public function review(ReviewInput $input): AiReview;

    /**
     * A stable identifier for cache keys, e.g. the model name.
     */
    public function fingerprint(): string;
}
