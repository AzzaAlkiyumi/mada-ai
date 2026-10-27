<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['role', 'content', 'status', 'model', 'prompt_tokens', 'completion_tokens', 'duration_ms'])]
class Message extends Model
{
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
}
