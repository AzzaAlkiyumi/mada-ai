<?php

namespace Tests\Unit;

use App\Services\Llm\LlmException;
use App\Services\Llm\OpenAiCompatibleClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenAiCompatibleClientTest extends TestCase
{
    private function client(): OpenAiCompatibleClient
    {
        return new OpenAiCompatibleClient([...config('llm'), 'base_url' => 'http://llm.test/v1', 'api_key' => 'secret-key']);
    }

    private function sse(array $chunks): string
    {
        return implode('', array_map(fn ($c) => 'data: '.json_encode($c)."\n\n", $chunks))."data: [DONE]\n\n";
    }

    public function test_it_streams_content_and_reads_usage(): void
    {
        Http::fake(['llm.test/*' => Http::response($this->sse([
            ['model' => 'qwen', 'choices' => [['delta' => ['role' => 'assistant']]]],
            ['model' => 'qwen', 'choices' => [['delta' => ['reasoning_content' => 'hidden']]]],
            ['model' => 'qwen', 'choices' => [['delta' => ['content' => 'Hello']]]],
            ['model' => 'qwen', 'choices' => [['delta' => ['content' => ' world']]]],
            ['model' => 'qwen', 'choices' => [], 'usage' => ['prompt_tokens' => 7, 'completion_tokens' => 2]],
        ]))]);

        $deltas = [];
        $result = $this->client()->streamChat([['role' => 'user', 'content' => 'hi']], function ($d) use (&$deltas) {
            $deltas[] = $d;

            return true;
        });

        $this->assertSame(['Hello', ' world'], $deltas);
        $this->assertSame('Hello world', $result->content);
        $this->assertFalse($result->stopped);
        $this->assertSame('qwen', $result->model);
        $this->assertSame(7, $result->promptTokens);
        $this->assertSame(2, $result->completionTokens);

        Http::assertSent(fn (Request $r) => $r->url() === 'http://llm.test/v1/chat/completions'
            && $r->hasHeader('Authorization', 'Bearer secret-key')
            && $r['stream'] === true
            && $r['chat_template_kwargs'] === ['enable_thinking' => false]);
    }

    public function test_callback_can_stop_generation(): void
    {
        Http::fake(['llm.test/*' => Http::response($this->sse([
            ['choices' => [['delta' => ['content' => 'one']]]],
            ['choices' => [['delta' => ['content' => 'two']]]],
        ]))]);

        $result = $this->client()->streamChat([], fn () => false);

        $this->assertSame('one', $result->content);
        $this->assertTrue($result->stopped);
    }

    public function test_http_errors_become_llm_exceptions(): void
    {
        Http::fake(['llm.test/*' => Http::response('boom', 503)]);

        $this->expectException(LlmException::class);
        $this->expectExceptionMessage('HTTP 503');

        $this->client()->streamChat([], fn () => true);
    }

    public function test_unreachable_service_becomes_llm_exception(): void
    {
        Http::fake(['llm.test/*' => Http::failedConnection()]);

        $this->expectException(LlmException::class);
        $this->expectExceptionMessage('not reachable');

        $this->client()->streamChat([], fn () => true);
    }
}
