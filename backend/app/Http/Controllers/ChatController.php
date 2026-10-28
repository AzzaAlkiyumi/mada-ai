<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\Message;
use App\Services\ChatService;
use App\Services\DocumentReader;
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
     * A message may carry one attachment: an image, or a document whose text is read.
     * Events: "meta" (ids), "delta" (text chunk), "done" (saved reply), "error".
     */
    public function store(Request $request, DocumentReader $reader): StreamedResponse
    {
        $data = $request->validate([
            'conversation_id' => ['nullable', 'integer'],
            'content' => ['required_without_all:image,file', 'nullable', 'string', 'max:'.config('llm.max_input_chars')],
            'image' => ['nullable', 'prohibits:file', 'file', 'mimes:jpg,jpeg,png,webp', 'max:'.config('llm.max_image_kb'), 'dimensions:max_width=4096,max_height=4096'],
            'file' => ['nullable', 'file', 'extensions:'.implode(',', DocumentReader::EXTENSIONS), 'max:'.config('llm.max_file_kb')],
        ]);

        $content = trim($data['content'] ?? '');
        $image = $request->file('image');
        $file = $request->file('file');
        abort_if($content === '' && ! $image && ! $file, 422, 'The message is empty.');

        // Read the document before anything is saved, so an unreadable file changes nothing.
        $document = $file ? $reader->read($file, config('llm.max_file_chars')) : null;

        $user = $request->user();

        $conversation = isset($data['conversation_id'])
            ? $user->conversations()->findOrFail($data['conversation_id'])
            : $this->chat->startConversation($user->id, $content !== '' ? $content : ($file?->getClientOriginalName() ?? 'Image'));

        $userMessage = $conversation->messages()->create([
            'role' => Message::ROLE_USER,
            'content' => $content,
            'image_path' => $image?->store("images/{$user->id}", 'local'),
            'image_mime' => $image?->getMimeType(),
            'file_name' => $file ? mb_substr($file->getClientOriginalName(), 0, 255) : null,
            'file_text' => $document['text'] ?? null,
        ]);

        return $this->streamReply($conversation, $userMessage, ['file_truncated' => $document['truncated'] ?? false]);
    }

    /**
     * Change one of the user's messages and answer it again. Everything after it is replaced.
     */
    public function edit(Request $request, int $message): StreamedResponse
    {
        $message = $this->findOwnMessage($request, $message, Message::ROLE_USER);

        $data = $request->validate([
            'content' => [$message->image_path || $message->file_name ? 'nullable' : 'required', 'string', 'max:'.config('llm.max_input_chars')],
        ]);

        $message->update(['content' => trim($data['content'] ?? '')]);
        $this->chat->discardAfter($message);

        return $this->streamReply($message->conversation, $message);
    }

    /**
     * Answer the user message before this reply again. The reply and anything after it are replaced.
     */
    public function regenerate(Request $request, int $message): StreamedResponse
    {
        $reply = $this->findOwnMessage($request, $message, Message::ROLE_ASSISTANT);

        $question = $reply->conversation->messages()
            ->where('role', Message::ROLE_USER)
            ->where('id', '<', $reply->id)
            ->reorder('id', 'desc')
            ->firstOrFail();

        $this->chat->discardAfter($question);

        return $this->streamReply($reply->conversation, $question);
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

    private function findOwnMessage(Request $request, int $id, string $role): Message
    {
        return Message::whereKey($id)
            ->where('role', $role)
            ->whereHas('conversation', fn ($q) => $q->where('user_id', $request->user()->id))
            ->with('conversation')
            ->firstOrFail();
    }

    /**
     * Create the assistant message and stream its reply to $userMessage.
     *
     * @param  array<string, mixed>  $meta  extra fields for the "meta" event
     */
    private function streamReply(Conversation $conversation, Message $userMessage, array $meta = []): StreamedResponse
    {
        $assistant = $conversation->messages()->create([
            'role' => Message::ROLE_ASSISTANT,
            'content' => '',
            'status' => Message::STATUS_STREAMING,
        ]);

        return response()->stream(function () use ($conversation, $userMessage, $assistant, $meta) {
            // Keep running after the browser disconnects so the partial reply is saved.
            ignore_user_abort(true);
            set_time_limit(config('llm.timeout') + 30);

            $this->send('meta', [
                'conversation' => $conversation->only(['id', 'title', 'created_at', 'updated_at']),
                'user_message' => $userMessage->toPublicArray(),
                'assistant_message_id' => $assistant->id,
                ...$meta,
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
