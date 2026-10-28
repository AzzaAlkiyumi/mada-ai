<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Services\ChatService;
use App\Services\Llm\LlmClient;
use App\Services\Llm\LlmException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
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
            'content' => ['required_without:image', 'nullable', 'string', 'max:'.config('llm.max_input_chars')],
            'image' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:'.config('llm.max_image_kb'), 'dimensions:max_width=4096,max_height=4096'],
        ]);

        $content = trim($data['content'] ?? '');
        $image = $request->file('image');
        abort_if($content === '' && ! $image, 422, 'The message is empty.');

        $user = $request->user();

        $conversation = isset($data['conversation_id'])
            ? $user->conversations()->findOrFail($data['conversation_id'])
            : $this->chat->startConversation($user->id, $content !== '' ? $content : 'Image');

        $userMessage = $conversation->messages()->create([
            'role' => Message::ROLE_USER,
            'content' => $content,
            'image_path' => $image?->store("images/{$user->id}", 'local'),
            'image_mime' => $image?->getMimeType(),
        ]);

        $assistant = $conversation->messages()->create([
            'role' => Message::ROLE_ASSISTANT,
            'content' => '',
            'status' => Message::STATUS_STREAMING,
        ]);

        return response()->stream(function () use ($conversation, $userMessage, $assistant) {
            // Keep running after the browser disconnects so the partial reply is saved.
            ignore_user_abort(true);
            set_time_limit(config('llm.timeout') + 30);

            $this->send('meta', [
                'conversation' => $conversation->only(['id', 'title', 'created_at', 'updated_at']),
                'user_message' => $userMessage->toPublicArray(),
                'assistant_message_id' => $assistant->id,
            ]);

            try {
                $message = $this->chat->reply($conversation, $assistant, function (string $delta) {
                    $this->send('delta', ['text' => $delta]);

                    return ! connection_aborted();
                });

                $this->send('done', ['message' => $message->toPublicArray()]);
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

    /**
     * The image attached to one of the user's own messages.
     */
    public function image(Request $request, int $message): BinaryFileResponse
    {
        $message = Message::whereKey($message)
            ->whereNotNull('image_path')
            ->whereHas('conversation', fn ($q) => $q->where('user_id', $request->user()->id))
            ->firstOrFail();

        abort_unless(Storage::disk('local')->exists($message->image_path), 404);

        return response()->file(Storage::disk('local')->path($message->image_path), [
            'Content-Type' => $message->image_mime,
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
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
