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
        $conversations = $request->user()->conversations()
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
