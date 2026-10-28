<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Llm\ChatResult;
use App\Services\Llm\LlmClient;
use App\Services\Llm\LlmException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Test double for the model service. Used only in tests; the app always
 * talks to the real self-hosted engine.
 */
class ScriptedLlm implements LlmClient
{
    public array $received = [];

    public function __construct(private array $chunks = [], private ?string $failWith = null) {}

    public function streamChat(array $messages, callable $onDelta): ChatResult
    {
        $this->received = $messages;

        if ($this->failWith) {
            throw new LlmException($this->failWith);
        }

        foreach ($this->chunks as $chunk) {
            $onDelta($chunk);
        }

        return new ChatResult(implode('', $this->chunks), false, 'test-model', 12, count($this->chunks));
    }

    public function health(): bool
    {
        return true;
    }
}

class ChatTest extends TestCase
{
    use RefreshDatabase;

    private function events(string $body): array
    {
        preg_match_all("/event: (\w+)\ndata: (.*)\n\n/", $body, $m, PREG_SET_ORDER);

        return array_map(fn ($e) => [$e[1], json_decode($e[2], true)], $m);
    }

    public function test_chat_streams_reply_and_saves_conversation(): void
    {
        $user = User::factory()->create();
        $this->app->instance(LlmClient::class, $llm = new ScriptedLlm(['مرحبًا', ' بك']));

        $response = $this->actingAs($user)->post('/api/chat', ['content' => 'السلام عليكم']);
        $response->assertOk();
        $this->assertStringStartsWith('text/event-stream', $response->headers->get('Content-Type'));

        $events = $this->events($response->streamedContent());
        $this->assertSame(['meta', 'delta', 'delta', 'done'], array_column($events, 0));
        $this->assertSame('مرحبًا بك', $events[3][1]['message']['content']);

        $conversation = Conversation::sole();
        $this->assertSame($user->id, $conversation->user_id);
        $this->assertSame('السلام عليكم', $conversation->title);
        $this->assertSame(
            [['user', 'السلام عليكم', 'complete'], ['assistant', 'مرحبًا بك', 'complete']],
            $conversation->messages->map(fn ($m) => [$m->role, $m->content, $m->status])->all(),
        );

        $this->assertSame('system', $llm->received[0]['role']);
        $this->assertSame(['role' => 'user', 'content' => 'السلام عليكم'], end($llm->received));
    }

    public function test_follow_up_message_sends_history(): void
    {
        $user = User::factory()->create();
        $conversation = $user->conversations()->create(['title' => 'Old']);
        $conversation->messages()->create(['role' => 'user', 'content' => 'first']);
        $conversation->messages()->create(['role' => 'assistant', 'content' => 'answer']);
        $conversation->messages()->create(['role' => 'assistant', 'content' => '', 'status' => 'error']);

        $this->app->instance(LlmClient::class, $llm = new ScriptedLlm(['ok']));

        $this->actingAs($user)->post('/api/chat', ['conversation_id' => $conversation->id, 'content' => 'second'])->streamedContent();

        $this->assertSame(
            ['system', 'user', 'assistant', 'user'],
            array_column($llm->received, 'role'),
        );
        $this->assertSame(1, Conversation::count());
    }

    public function test_model_failure_is_reported_and_saved_as_error(): void
    {
        $user = User::factory()->create();
        $this->app->instance(LlmClient::class, new ScriptedLlm(failWith: 'The model service is not reachable.'));

        $events = $this->events($this->actingAs($user)->post('/api/chat', ['content' => 'hi'])->streamedContent());

        $this->assertSame(['meta', 'error'], array_column($events, 0));
        $this->assertSame('The model service is not reachable.', $events[1][1]['message']);
        $this->assertSame(Message::STATUS_ERROR, Message::where('role', 'assistant')->sole()->status);
    }

    public function test_message_length_is_limited(): void
    {
        config(['llm.max_input_chars' => 10]);
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/chat', ['content' => str_repeat('a', 11)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('content');
    }

    public function test_users_cannot_reach_other_users_conversations(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $conversation = $owner->conversations()->create(['title' => 'Private']);
        $this->app->instance(LlmClient::class, new ScriptedLlm(['x']));

        $this->actingAs($other)->getJson("/api/conversations/{$conversation->id}")->assertNotFound();
        $this->actingAs($other)->deleteJson("/api/conversations/{$conversation->id}")->assertNotFound();
        $this->actingAs($other)->postJson('/api/chat', ['conversation_id' => $conversation->id, 'content' => 'x'])->assertNotFound();
        $this->actingAs($other)->getJson('/api/conversations')->assertJsonCount(0, 'conversations');
    }

    public function test_image_is_saved_and_sent_to_the_model(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $this->app->instance(LlmClient::class, $llm = new ScriptedLlm(['a cat']));

        $events = $this->events($this->actingAs($user)->post('/api/chat', [
            'content' => 'What is this?',
            'image' => UploadedFile::fake()->image('cat.jpg', 64, 64),
        ])->streamedContent());

        $this->assertSame(['meta', 'delta', 'done'], array_column($events, 0));
        $this->assertTrue($events[0][1]['user_message']['has_image']);
        $this->assertArrayNotHasKey('image_path', $events[0][1]['user_message']);

        $message = Message::where('role', 'user')->sole();
        Storage::disk('local')->assertExists($message->image_path);
        $this->assertSame('image/jpeg', $message->image_mime);

        $content = end($llm->received)['content'];
        $this->assertSame(['type' => 'text', 'text' => 'What is this?'], $content[0]);
        $this->assertSame('image_url', $content[1]['type']);
        $this->assertStringStartsWith('data:image/jpeg;base64,', $content[1]['image_url']['url']);

        $this->actingAs($user)->get("/api/messages/{$message->id}/image")
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');
    }

    public function test_image_alone_is_a_valid_message_and_older_images_become_notes(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $this->app->instance(LlmClient::class, $llm = new ScriptedLlm(['ok']));

        $this->actingAs($user)->post('/api/chat', ['image' => UploadedFile::fake()->image('a.png', 32, 32)])->streamedContent();
        $conversation = Conversation::sole();
        $this->assertSame('Image', $conversation->title);
        $this->assertCount(1, end($llm->received)['content']);

        $this->actingAs($user)->post('/api/chat', ['conversation_id' => $conversation->id, 'content' => 'and now?'])->streamedContent();

        $this->assertSame(
            [['user', '[image]'], ['assistant', 'ok'], ['user', 'and now?']],
            array_map(fn ($m) => [$m['role'], $m['content']], array_slice($llm->received, 1)),
        );
    }

    public function test_image_rules_and_privacy(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $this->app->instance(LlmClient::class, new ScriptedLlm(['x']));

        $this->actingAs($owner)->postJson('/api/chat', ['image' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')])
            ->assertJsonValidationErrors('image');
        $this->actingAs($owner)->postJson('/api/chat', ['content' => ''])
            ->assertJsonValidationErrors('content');

        $this->actingAs($owner)->post('/api/chat', ['image' => UploadedFile::fake()->image('a.png')])->streamedContent();
        $message = Message::where('role', 'user')->sole();

        $this->actingAs($other)->getJson("/api/messages/{$message->id}/image")->assertNotFound();

        $this->actingAs($owner)->deleteJson("/api/conversations/{$message->conversation_id}")->assertOk();
        Storage::disk('local')->assertMissing($message->image_path);
    }

    public function test_conversation_can_be_listed_renamed_and_deleted(): void
    {
        $user = User::factory()->create();
        $conversation = $user->conversations()->create(['title' => 'One']);
        $conversation->messages()->create(['role' => 'user', 'content' => 'hello']);

        $this->actingAs($user)->getJson('/api/conversations')->assertJsonPath('conversations.0.title', 'One');
        $this->actingAs($user)->getJson("/api/conversations/{$conversation->id}")->assertJsonPath('messages.0.content', 'hello');
        $this->actingAs($user)->patchJson("/api/conversations/{$conversation->id}", ['title' => 'Renamed'])->assertJsonPath('conversation.title', 'Renamed');
        $this->actingAs($user)->deleteJson("/api/conversations/{$conversation->id}")->assertOk();

        $this->assertSame(0, Conversation::count());
        $this->assertSame(0, Message::count());
    }
}
