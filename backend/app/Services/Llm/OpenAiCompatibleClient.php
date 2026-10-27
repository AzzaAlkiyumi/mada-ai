<?php

namespace App\Services\Llm;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\StreamInterface;
use Throwable;

/**
 * Client for engines that expose the OpenAI chat completions API:
 * llama.cpp (llama-server), Ollama, vLLM and SGLang.
 */
class OpenAiCompatibleClient implements LlmClient
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(private readonly array $config) {}

    public function streamChat(array $messages, callable $onDelta): ChatResult
    {
        $payload = [
            'model' => $this->config['model'],
            'messages' => $messages,
            'stream' => true,
            'stream_options' => ['include_usage' => true],
            'max_tokens' => $this->config['max_tokens'],
            'temperature' => $this->config['temperature'],
            'chat_template_kwargs' => ['enable_thinking' => $this->config['thinking']],
        ];

        try {
            $response = $this->request()
                ->withOptions(['stream' => true, 'read_timeout' => $this->config['timeout']])
                ->post($this->config['base_url'].'/chat/completions', $payload);
        } catch (ConnectionException $e) {
            Log::warning('LLM service unreachable', ['error' => $e->getMessage()]);

            throw new LlmException('The model service is not reachable. Make sure it is running.', 0, $e);
        }

        if ($response->failed()) {
            Log::warning('LLM service returned an error', ['status' => $response->status()]);

            throw new LlmException("The model service returned an error (HTTP {$response->status()}).");
        }

        return $this->readStream($response->toPsrResponse()->getBody(), $onDelta);
    }

    public function health(): bool
    {
        try {
            return $this->request()->timeout(5)->get($this->config['base_url'].'/models')->successful();
        } catch (Throwable) {
            return false;
        }
    }

    private function request(): PendingRequest
    {
        $request = Http::acceptJson()
            ->connectTimeout($this->config['connect_timeout'])
            ->timeout($this->config['timeout']);

        if (! empty($this->config['api_key'])) {
            $request = $request->withToken($this->config['api_key']);
        }

        return $request;
    }

    /**
     * Parse the server-sent events stream, passing text deltas to the callback.
     */
    private function readStream(StreamInterface $body, callable $onDelta): ChatResult
    {
        $content = '';
        $buffer = '';
        $model = null;
        $usage = null;
        $stopped = false;

        try {
            while (! $body->eof()) {
                $buffer .= $body->read(1024);

                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line = trim(substr($buffer, 0, $pos));
                    $buffer = substr($buffer, $pos + 1);

                    if (! str_starts_with($line, 'data:')) {
                        continue;
                    }

                    $data = trim(substr($line, 5));

                    if ($data === '[DONE]') {
                        break 2;
                    }

                    $chunk = json_decode($data, true);

                    if (! is_array($chunk)) {
                        continue;
                    }

                    if (isset($chunk['error'])) {
                        Log::warning('LLM stream error', ['error' => $chunk['error']['message'] ?? 'unknown']);

                        throw new LlmException('The model stopped with an error while answering.');
                    }

                    $model ??= $chunk['model'] ?? null;
                    $usage = $chunk['usage'] ?? $usage;
                    $delta = $chunk['choices'][0]['delta']['content'] ?? '';

                    if ($delta === '') {
                        continue;
                    }

                    $content .= $delta;

                    if ($onDelta($delta) === false) {
                        $stopped = true;
                        break 2;
                    }
                }
            }
        } catch (LlmException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::warning('LLM stream interrupted', ['error' => $e->getMessage()]);

            throw new LlmException('The connection to the model was interrupted or timed out.', 0, $e);
        } finally {
            // Closing the connection tells the engine to stop generating.
            $body->close();
        }

        return new ChatResult(
            content: $content,
            stopped: $stopped,
            model: $model,
            promptTokens: $usage['prompt_tokens'] ?? null,
            completionTokens: $usage['completion_tokens'] ?? null,
        );
    }
}
