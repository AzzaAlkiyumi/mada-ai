<?php

namespace App\Services\Llm;

final class ChatResult
{
    public function __construct(
        public readonly string $content,
        public readonly bool $stopped,
        public readonly ?string $model = null,
        public readonly ?int $promptTokens = null,
        public readonly ?int $completionTokens = null,
    ) {}
}
