<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Message;
use App\Services\Llm\LlmClient;
use App\Services\Llm\LlmException;
use Illuminate\Support\Facades\Storage;
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
     * Only the newest message sends its image to the model; reading an image
     * is slow on a CPU, so older images appear as an "[image]" note.
     *
     * @return array<int, array{role: string, content: string|array}>
     */
    public function buildContext(Conversation $conversation, Message $assistant): array
    {
        $history = $conversation->messages()
            ->where('id', '<', $assistant->id)
            ->where(fn ($q) => $q->where('content', '!=', '')->orWhereNotNull('image_path')->orWhereNotNull('file_name'))
            ->where(fn ($q) => $q->where('role', Message::ROLE_USER)->orWhere('status', '!=', Message::STATUS_ERROR))
            ->reorder('id', 'desc')
            ->limit(config('llm.context_messages'))
            ->get(['role', 'content', 'image_path', 'image_mime', 'file_name', 'file_text']);

        $budget = config('llm.context_chars');
        $kept = [];

        foreach ($history as $message) {
            $text = $message->content;

            // Document text travels with every request, so follow-up questions can use it.
            if ($message->file_name !== null) {
                $text = trim("[File: {$message->file_name}]\n<document>\n{$message->file_text}\n</document>\n\n".$text);
            }

            if ($message->image_path !== null && $kept !== []) {
                $text = trim("[image]\n".$text);
            }

            $length = mb_strlen($text);

            // Always keep the newest message, even when it alone exceeds the budget.
            if ($kept !== [] && $length > $budget) {
                break;
            }

            $budget -= $length;
            $content = $kept === [] && $message->image_path !== null ? $this->withImage($message) : $text;
            array_unshift($kept, ['role' => $message->role, 'content' => $content]);
        }

        return [['role' => 'system', 'content' => $this->systemPrompt($conversation)], ...$kept];
    }

    /**
     * Delete every message after $message in its conversation, with their images.
     */
    public function discardAfter(Message $message): void
    {
        $later = Message::where('conversation_id', $message->conversation_id)->where('id', '>', $message->id);

        Storage::disk('local')->delete((clone $later)->whereNotNull('image_path')->pluck('image_path')->all());
        $later->delete();
    }

    private function systemPrompt(Conversation $conversation): string
    {
        $prompt = config('llm.system_prompt');
        $instructions = trim((string) $conversation->user()->value('instructions'));

        return $instructions === ''
            ? $prompt
            : $prompt."\n\nThe user's personal instructions (follow them unless they conflict with the rules above):\n".$instructions;
    }

    /**
     * OpenAI-style multipart content: the text (if any) and the image as a data URL.
     *
     * @return array<int, array<string, mixed>>
     */
    private function withImage(Message $message): array
    {
        $parts = [];

        if ($message->content !== '') {
            $parts[] = ['type' => 'text', 'text' => $message->content];
        }

        $data = base64_encode(Storage::disk('local')->get($message->image_path));
        $parts[] = ['type' => 'image_url', 'image_url' => ['url' => "data:{$message->image_mime};base64,{$data}"]];

        return $parts;
    }

    private function titleFrom(string $message): string
    {
        $title = Str::of($message)->squish()->limit(60, '…')->toString();

        return $title !== '' ? $title : 'New conversation';
    }
}
