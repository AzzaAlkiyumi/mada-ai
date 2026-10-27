<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Message;
use App\Services\Llm\LlmClient;
use App\Services\Llm\LlmException;
use Illuminate\Support\Str;

class ChatService
{
    public function __construct(private readonly LlmClient $llm) {}

    public function startConversation(int $userId, string $firstMessage): Conversation
    {
        $conversation = new Conversation(['title' => $this->titleFrom($firstMessage)]);
        $conversation->user_id = $userId;
        $conversation->save();

        return $conversation;
    }

    /**
     * Generate the assistant reply for the latest user message, streaming chunks to $onDelta.
     *
     * @param  callable(string): bool  $onDelta
     */
    public function reply(Conversation $conversation, Message $assistant, callable $onDelta): Message
    {
        $started = hrtime(true);

        try {
            $result = $this->llm->streamChat($this->buildContext($conversation, $assistant), $onDelta);

            $assistant->fill([
                'content' => $result->content,
                'status' => $result->stopped ? Message::STATUS_STOPPED : Message::STATUS_COMPLETE,
                'model' => $result->model,
                'prompt_tokens' => $result->promptTokens,
                'completion_tokens' => $result->completionTokens,
            ]);
        } catch (LlmException $e) {
            $assistant->status = Message::STATUS_ERROR;

            throw $e;
        } finally {
            $assistant->duration_ms = (int) ((hrtime(true) - $started) / 1_000_000);
            $assistant->save();
            $conversation->touch();
        }

        return $assistant;
    }

    /**
     * The system prompt plus as much recent history as the limits allow.
     *
     * @return array<int, array{role: string, content: string}>
     */
    public function buildContext(Conversation $conversation, Message $assistant): array
    {
        $history = $conversation->messages()
            ->where('id', '<', $assistant->id)
            ->where('content', '!=', '')
            ->where(fn ($q) => $q->where('role', Message::ROLE_USER)->orWhere('status', '!=', Message::STATUS_ERROR))
            ->reorder('id', 'desc')
            ->limit(config('llm.context_messages'))
            ->get(['role', 'content']);

        $budget = config('llm.context_chars');
        $kept = [];

        foreach ($history as $message) {
            $length = mb_strlen($message->content);

            // Always keep the newest message, even when it alone exceeds the budget.
            if ($kept !== [] && $length > $budget) {
                break;
            }

            $budget -= $length;
            array_unshift($kept, ['role' => $message->role, 'content' => $message->content]);
        }

        return [['role' => 'system', 'content' => config('llm.system_prompt')], ...$kept];
    }

    private function titleFrom(string $message): string
    {
        $title = Str::of($message)->squish()->limit(60, '…')->toString();

        return $title !== '' ? $title : 'New conversation';
    }
}
