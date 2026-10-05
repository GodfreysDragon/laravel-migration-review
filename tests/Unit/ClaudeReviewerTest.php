<?php

namespace MigrationReview\Tests\Unit;

use Anthropic\Client;
use GuzzleHttp\Psr7\Response;
use MigrationReview\Ai\AiReviewFailed;
use MigrationReview\Ai\ClaudeReviewer;
use MigrationReview\Ai\PromptBuilder;
use MigrationReview\Ai\ReviewInput;
use MigrationReview\Analysis\Severity;
use MigrationReview\Tests\TestCase;
use PHPUnit\Framework\TestCase as BaseTestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Exercises ClaudeReviewer through the real Anthropic SDK with a stubbed HTTP transport,
 * so the request it builds and the response handling are both checked without network calls.
 */
class ClaudeReviewerTest extends BaseTestCase
{
    private ?RequestInterface $sent = null;

    private function reviewer(int $status, array $body): ClaudeReviewer
    {
        $transport = new class($status, $body, $this) implements ClientInterface
        {
            public function __construct(private int $status, private array $body, private ClaudeReviewerTest $test) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->test->capture($request);

                return new Response($this->status, ['Content-Type' => 'application/json'], json_encode($this->body));
            }
        };

        $client = new Client(apiKey: 'sk-test', requestOptions: ['transporter' => $transport, 'maxRetries' => 0]);

        return new ClaudeReviewer($client, new PromptBuilder, 'claude-opus-5-5', 'medium', 16000);
    }

    public function capture(RequestInterface $request): void
    {
        $this->sent = $request;
    }

    private function message(string $text, string $stopReason = 'end_turn'): array
    {
        return [
            'id' => 'msg_test',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-opus-5-5',
            'content' => [['type' => 'text', 'text' => $text, 'citations' => null]],
            'stop_reason' => $stopReason,
            'stop_sequence' => null,
            'stop_details' => null,
            'usage' => ['input_tokens' => 900, 'output_tokens' => 120],
        ];
    }

    private function input(): ReviewInput
    {
        return new ReviewInput(TestCase::fixture('2026_01_02_000000_add_status_to_orders_table.php'), 'pgsql');
    }

    public function test_it_sends_a_structured_output_request_with_fallbacks_and_parses_the_reply(): void
    {
        $reply = json_encode([
            'summary' => 'Fails on a non-empty orders table.',
            'findings' => [[
                'severity' => 'medium',
                'title' => 'currency default hides missing data',
                'explanation' => 'Existing orders all become USD.',
                'line' => 13,
                'suggestion' => 'Backfill from the payment provider.',
                'table' => 'orders',
            ]],
        ]);

        $review = $this->reviewer(200, $this->message($reply))->review($this->input());

        $this->assertSame('Fails on a non-empty orders table.', $review->summary);
        $this->assertSame(Severity::Medium, $review->findings[0]->severity);
        $this->assertSame(13, $review->findings[0]->line);

        $this->assertNotNull($this->sent);
        $this->assertStringEndsWith('/v1/messages', $this->sent->getUri()->getPath());
        $this->assertStringContainsString('server-side-fallback-2026-07-01', $this->sent->getHeaderLine('anthropic-beta'));

        $body = json_decode((string) $this->sent->getBody(), true);
        $this->assertSame('claude-opus-5-5', $body['model']);
        $this->assertSame('default', $body['fallbacks']);
        $this->assertSame('medium', $body['output_config']['effort']);
        $this->assertSame('json_schema', $body['output_config']['format']['type']);
        $this->assertSame((new PromptBuilder)->schema(), $body['output_config']['format']['schema']);
        $this->assertStringContainsString('<static_findings>', $body['messages'][0]['content']);
        $this->assertArrayNotHasKey('thinking', $body);
    }

    public function test_refusals_become_review_failures(): void
    {
        $this->expectException(AiReviewFailed::class);
        $this->expectExceptionMessage('declined');

        $this->reviewer(200, $this->message('', 'refusal'))->review($this->input());
    }

    public function test_invalid_json_becomes_a_review_failure(): void
    {
        $this->expectException(AiReviewFailed::class);
        $this->expectExceptionMessage('invalid JSON');

        $this->reviewer(200, $this->message('not json'))->review($this->input());
    }

    public function test_bad_api_keys_get_a_clear_message(): void
    {
        $this->expectException(AiReviewFailed::class);
        $this->expectExceptionMessage('ANTHROPIC_API_KEY');

        $this->reviewer(401, ['type' => 'error', 'error' => ['type' => 'authentication_error', 'message' => 'invalid x-api-key']])->review($this->input());
    }
}
