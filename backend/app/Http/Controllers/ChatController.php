<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Services\ChatService;
use App\Services\Llm\LlmClient;
use App\Services\Llm\LlmException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ChatController extends Controller
{
    public function __construct(private readonly ChatService $chat) {}

    /**
     * Save the user's message and stream the model's reply as server-sent events.
     *
     * Events: "meta" (ids), "delta" (text chunk), "done" (saved reply), "error".
     */
    public function store(Request $request): StreamedResponse
    {
        $data = $request->validate([
            'conversation_id' => ['nullable', 'integer'],
            'content' => ['required', 'string', 'max:'.config('llm.max_input_chars')],
        ]);

        $content = trim($data['content']);
        abort_if($content === '', 422, 'The message is empty.');

        $user = $request->user();

        $conversation = isset($data['conversation_id'])
            ? $user->conversations()->findOrFail($data['conversation_id'])
            : $this->chat->startConversation($user->id, $content);

        $conversation->messages()->create(['role' => Message::ROLE_USER, 'content' => $content]);

        $assistant = $conversation->messages()->create([
            'role' => Message::ROLE_ASSISTANT,
            'content' => '',
            'status' => Message::STATUS_STREAMING,
        ]);

        return response()->stream(function () use ($conversation, $assistant) {
            // Keep running after the browser disconnects so the partial reply is saved.
            ignore_user_abort(true);
            set_time_limit(config('llm.timeout') + 30);

            $this->send('meta', [
                'conversation' => $conversation->only(['id', 'title', 'created_at', 'updated_at']),
                'assistant_message_id' => $assistant->id,
            ]);

            try {
                $message = $this->chat->reply($conversation, $assistant, function (string $delta) {
                    $this->send('delta', ['text' => $delta]);

                    return ! connection_aborted();
                });

                $this->send('done', ['message' => $message->only(['id', 'role', 'content', 'status', 'model', 'completion_tokens', 'duration_ms', 'created_at'])]);
            } catch (LlmException $e) {
                $this->send('error', ['message' => $e->getMessage()]);
            } catch (Throwable $e) {
                Log::error('Chat reply failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
                $this->send('error', ['message' => 'Unexpected server error.']);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    public function status(LlmClient $llm): JsonResponse
    {
        return response()->json([
            'online' => $llm->health(),
            'model' => config('llm.model'),
        ]);
    }

    private function send(string $event, array $data): void
    {
        echo "event: {$event}\n";
        echo 'data: '.json_encode($data, JSON_UNESCAPED_UNICODE)."\n\n";

        if (ob_get_level() > 0) {
            ob_flush();
        }

        flush();
    }
}
