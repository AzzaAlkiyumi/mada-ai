<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['role', 'content', 'image_path', 'image_mime', 'file_name', 'file_text', 'status', 'model', 'prompt_tokens', 'completion_tokens', 'duration_ms'])]
class Message extends Model
{
    /** Columns returned to the browser; image_path and file_text stay on the server. */
    public const PUBLIC_FIELDS = ['id', 'role', 'content', 'file_name', 'status', 'model', 'completion_tokens', 'duration_ms', 'created_at'];

    public const ROLE_USER = 'user';

    public const ROLE_ASSISTANT = 'assistant';

    public const STATUS_STREAMING = 'streaming';

    public const STATUS_COMPLETE = 'complete';

    public const STATUS_STOPPED = 'stopped';

    public const STATUS_ERROR = 'error';

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [...$this->only(self::PUBLIC_FIELDS), 'has_image' => $this->image_path !== null];
    }
}
