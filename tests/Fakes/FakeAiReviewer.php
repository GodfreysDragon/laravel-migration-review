<?php

namespace MigrationReview\Tests\Fakes;

use MigrationReview\Ai\AiReview;
use MigrationReview\Ai\AiReviewer;
use MigrationReview\Ai\AiReviewFailed;
use MigrationReview\Ai\ReviewInput;

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
