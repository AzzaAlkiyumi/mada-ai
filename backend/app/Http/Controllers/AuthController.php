<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        if (! Auth::guard('web')->attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        $request->session()->regenerate();

        return response()->json(['user' => $this->present($request->user())]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['ok' => true]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->present($request->user())]);
    }

    /**
     * Save the personal instructions that are added to every chat.
     */
    public function updateInstructions(Request $request): JsonResponse
    {
        $data = $request->validate([
            'instructions' => ['nullable', 'string', 'max:'.config('llm.max_instructions_chars')],
        ]);

        $instructions = trim($data['instructions'] ?? '');
        $request->user()->update(['instructions' => $instructions !== '' ? $instructions : null]);

        return response()->json(['user' => $this->present($request->user())]);
    }

    private function present($user): array
    {
        return ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'instructions' => $user->instructions ?? ''];
    }
}
