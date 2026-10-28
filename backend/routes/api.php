<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\ConversationController;
use Illuminate\Support\Facades\Route;

// There is no registration route: the single user is created with `php artisan user:create`.
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    Route::get('/conversations', [ConversationController::class, 'index']);
    Route::get('/conversations/{conversation}', [ConversationController::class, 'show']);
    Route::patch('/conversations/{conversation}', [ConversationController::class, 'update']);
    Route::delete('/conversations/{conversation}', [ConversationController::class, 'destroy']);

    Route::post('/chat', [ChatController::class, 'store'])->middleware('throttle:chat');
    Route::get('/messages/{message}/image', [ChatController::class, 'image']);
    Route::get('/model/status', [ChatController::class, 'status']);
});
