<?php

namespace App\Http\Controllers;

use App\Models\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ConversationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('q', ''));

        $conversations = $request->user()->conversations()
            // Search titles, message text and attached file names.
            ->when($search !== '', function ($query) use ($search) {
                $like = '%'.addcslashes(mb_substr($search, 0, 100), '\\%_').'%';

                $query->where(fn ($q) => $q
                    ->where('title', 'like', $like)
                    ->orWhereHas('messages', fn ($m) => $m->where('content', 'like', $like)->orWhere('file_name', 'like', $like)));
            })
            ->latest('updated_at')
            ->limit(200)
            ->get(['id', 'title', 'created_at', 'updated_at']);

        return response()->json(['conversations' => $conversations]);
    }

    public function show(Request $request, int $conversation): JsonResponse
    {
        $conversation = $request->user()->conversations()->findOrFail($conversation);

        return response()->json([
            'conversation' => $conversation->only(['id', 'title', 'created_at', 'updated_at']),
            'messages' => $conversation->messages()
                ->get([...Message::PUBLIC_FIELDS, 'image_path'])
                ->map(fn (Message $message) => $message->toPublicArray()),
        ]);
    }

    public function update(Request $request, int $conversation): JsonResponse
    {
        $conversation = $request->user()->conversations()->findOrFail($conversation);

        $conversation->update($request->validate([
            'title' => ['required', 'string', 'max:120'],
        ]));

        return response()->json(['conversation' => $conversation->only(['id', 'title', 'created_at', 'updated_at'])]);
    }

    public function destroy(Request $request, int $conversation): JsonResponse
    {
        $conversation = $request->user()->conversations()->findOrFail($conversation);
        $images = $conversation->messages()->whereNotNull('image_path')->pluck('image_path')->all();

        $conversation->delete();
        Storage::disk('local')->delete($images);

        return response()->json(['ok' => true]);
    }
}
