<?php

namespace App\Services\Llm;

interface LlmClient
{
    /**
     * Stream a chat completion from the model service.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  callable(string): bool  $onDelta  Receives each text chunk; return false to stop generating.
     */
    public function streamChat(array $messages, callable $onDelta): ChatResult;

    /**
     * Whether the model service is reachable.
     */
    public function health(): bool;
}
