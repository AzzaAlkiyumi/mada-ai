<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
                ->get(['id', 'role', 'content', 'status', 'model', 'completion_tokens', 'duration_ms', 'created_at']),
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
        $request->user()->conversations()->findOrFail($conversation)->delete();

        return response()->json(['ok' => true]);
    }
}
