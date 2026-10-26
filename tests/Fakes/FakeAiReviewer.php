<?php

namespace GodfreysDragon\MigrationReview\Tests\Fakes;

use GodfreysDragon\MigrationReview\Ai\AiReview;
use GodfreysDragon\MigrationReview\Ai\AiReviewer;
use GodfreysDragon\MigrationReview\Ai\AiReviewFailed;
use GodfreysDragon\MigrationReview\Ai\ReviewInput;

class FakeAiReviewer implements AiReviewer
{
    public int $calls = 0;

    /** @var list<ReviewInput> */
    public array $inputs = [];

    public function __construct(
        private readonly ?AiReview $review = null,
        private readonly ?string $failure = null,
    ) {}

    public function review(ReviewInput $input): AiReview
    {
        $this->calls++;
        $this->inputs[] = $input;

        if ($this->failure !== null) {
            throw new AiReviewFailed($this->failure);
        }

        return $this->review ?? new AiReview('', []);
    }

    public function fingerprint(): string
    {
        return 'fake';
    }
}
