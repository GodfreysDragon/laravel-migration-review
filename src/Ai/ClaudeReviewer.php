<?php

namespace GodfreysDragon\MigrationReview\Ai;

use Anthropic\Beta\Messages\BetaOutputConfig\Effort;
use Anthropic\Beta\Messages\BetaTextBlock;
use Anthropic\Client;
use Anthropic\Core\Exceptions\AnthropicException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\RateLimitException;

/**
 * Reviews a migration with Claude via the official Anthropic PHP SDK.
 *
 * Uses structured outputs so the reply is always schema-valid JSON, and opts into
 * server-side fallbacks so a classifier refusal is retried on another model instead
 * of producing an empty review.
 */
class ClaudeReviewer implements AiReviewer
{
    private const FALLBACK_BETA = 'server-side-fallback-2026-07-01';

    public function __construct(
        private readonly Client $client,
        private readonly PromptBuilder $prompts,
        private readonly string $model = 'claude-opus-5-5',
        private readonly string $effort = 'medium',
        private readonly int $maxTokens = 16000,
    ) {}

    public function review(ReviewInput $input): AiReview
    {
        $effort = Effort::tryFrom($this->effort)
            ?? throw new AiReviewFailed("Invalid AI effort '{$this->effort}'. Use low, medium, high, xhigh or max (MIGRATION_REVIEW_EFFORT).");

        try {
            $message = $this->client->beta->messages->create(
                maxTokens: $this->maxTokens,
                messages: [['role' => 'user', 'content' => $this->prompts->user($input)]],
                model: $this->model,
                system: $this->prompts->system(),
                outputConfig: [
                    'effort' => $effort,
                    'format' => ['type' => 'json_schema', 'schema' => $this->prompts->schema()],
                ],
                fallbacks: 'default',
                betas: [self::FALLBACK_BETA],
            );
        } catch (AuthenticationException) {
            throw new AiReviewFailed('Anthropic rejected the API key. Check ANTHROPIC_API_KEY.');
        } catch (RateLimitException) {
            throw new AiReviewFailed('Anthropic rate limit reached; AI review skipped.');
        } catch (AnthropicException $e) {
            throw new AiReviewFailed('AI review failed: '.$e->getMessage(), previous: $e);
        }

        if ($message->stopReason === 'refusal') {
            throw new AiReviewFailed('The model declined to review this migration.');
        }

        if ($message->stopReason === 'max_tokens') {
            throw new AiReviewFailed('The AI review was cut off (max_tokens reached).');
        }

        $text = '';
        foreach ($message->content as $block) {
            if ($block instanceof BetaTextBlock) {
                $text .= $block->text;
            }
        }

        $data = json_decode($text, true);

        if (! is_array($data)) {
            throw new AiReviewFailed('The AI review returned invalid JSON.');
        }

        return AiReview::fromArray($data);
    }

    public function fingerprint(): string
    {
        return $this->model.'|'.$this->effort;
    }
}
