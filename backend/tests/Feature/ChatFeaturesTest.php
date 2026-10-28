<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Llm\LlmClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/**
 * Search, personal instructions, editing, regenerating and document attachments.
 */
class ChatFeaturesTest extends TestCase
{
    use RefreshDatabase;

    private function chatWith(User $user, array $data, array $chunks = ['ok']): ScriptedLlm
    {
        $this->app->instance(LlmClient::class, $llm = new ScriptedLlm($chunks));
        $this->actingAs($user)->post('/api/chat', $data)->streamedContent();

        return $llm;
    }

    /** A one-page PDF with a line of text, built with correct xref offsets. */
    private function pdf(string $text): UploadedFile
    {
        $stream = "BT /F1 18 Tf 72 720 Td ({$text}) Tj ET";
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $i => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1)." 0 obj\n{$object}\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= 'xref\n0 '.(count($objects) + 1)."\n0000000000 65535 f \n";
        $pdf = str_replace('xref\n', "xref\n", $pdf);
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= 'trailer << /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";

        return UploadedFile::fake()->createWithContent('report.pdf', $pdf);
    }

    private function docx(string $paragraph1, string $paragraph2): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'docx');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('word/document.xml', '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
            ."<w:p><w:r><w:t>{$paragraph1}</w:t></w:r></w:p><w:p><w:r><w:t>{$paragraph2}</w:t></w:r></w:p></w:body></w:document>");
        $zip->close();

        return UploadedFile::fake()->createWithContent('notes.docx', file_get_contents($path));
    }

    public function test_conversations_can_be_searched_by_title_message_and_file_name(): void
    {
        $user = User::factory()->create();
        $a = $user->conversations()->create(['title' => 'Trip to Nizwa']);
        $b = $user->conversations()->create(['title' => 'Other']);
        $b->messages()->create(['role' => 'user', 'content' => 'كيف أطبخ المشاكيك؟']);
        $c = $user->conversations()->create(['title' => 'Third']);
        $c->messages()->create(['role' => 'user', 'content' => '', 'file_name' => 'budget-2026.pdf', 'file_text' => 'x']);
        User::factory()->create()->conversations()->create(['title' => 'Nizwa (someone else)']);

        $ids = fn (string $q) => collect($this->actingAs($user)->getJson('/api/conversations?q='.urlencode($q))->json('conversations'))->pluck('id')->all();

        $this->assertSame([$a->id], $ids('nizwa'));
        $this->assertSame([$b->id], $ids('المشاكيك'));
        $this->assertSame([$c->id], $ids('budget'));
        $this->assertSame([], $ids('100%'));
        $this->assertCount(3, $ids(''));
    }

    public function test_personal_instructions_are_saved_and_added_to_the_system_prompt(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->putJson('/api/me/instructions', ['instructions' => '  أجب باختصار.  '])
            ->assertOk()
            ->assertJsonPath('user.instructions', 'أجب باختصار.');
        $this->actingAs($user)->getJson('/api/me')->assertJsonPath('user.instructions', 'أجب باختصار.');

        $llm = $this->chatWith($user, ['content' => 'hi']);
        $this->assertStringEndsWith("\nأجب باختصار.", $llm->received[0]['content']);
        $this->assertStringStartsWith(config('llm.system_prompt'), $llm->received[0]['content']);

        $this->actingAs($user)->putJson('/api/me/instructions', ['instructions' => str_repeat('a', 2001)])->assertJsonValidationErrors('instructions');
        $this->actingAs($user)->putJson('/api/me/instructions', ['instructions' => ''])->assertJsonPath('user.instructions', '');
        $this->assertNull($user->fresh()->instructions);
    }

    public function test_editing_a_message_replaces_everything_after_it(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $conversation = $user->conversations()->create(['title' => 'T']);
        $first = $conversation->messages()->create(['role' => 'user', 'content' => 'first']);
        $conversation->messages()->create(['role' => 'assistant', 'content' => 'answer 1']);
        Storage::disk('local')->put('images/1/later.jpg', 'x');
        $conversation->messages()->create(['role' => 'user', 'content' => 'second', 'image_path' => 'images/1/later.jpg', 'image_mime' => 'image/jpeg']);
        $conversation->messages()->create(['role' => 'assistant', 'content' => 'answer 2']);

        $this->app->instance(LlmClient::class, $llm = new ScriptedLlm(['new answer']));
        $this->actingAs($user)->post("/api/messages/{$first->id}/edit", ['content' => 'first, edited'])->streamedContent();

        $this->assertSame(
            [['user', 'first, edited'], ['assistant', 'new answer']],
            $conversation->messages()->get()->map(fn ($m) => [$m->role, $m->content])->all(),
        );
        Storage::disk('local')->assertMissing('images/1/later.jpg');
        $this->assertSame(['role' => 'user', 'content' => 'first, edited'], end($llm->received));
    }

    public function test_regenerate_replaces_the_reply(): void
    {
        $user = User::factory()->create();
        $conversation = $user->conversations()->create(['title' => 'T']);
        $conversation->messages()->create(['role' => 'user', 'content' => 'question']);
        $old = $conversation->messages()->create(['role' => 'assistant', 'content' => 'weak answer']);

        $this->app->instance(LlmClient::class, $llm = new ScriptedLlm(['better answer']));
        $this->actingAs($user)->post("/api/messages/{$old->id}/regenerate")->streamedContent();

        $this->assertSame(['question', 'better answer'], $conversation->messages()->pluck('content')->all());
        $this->assertSame(['system', 'user'], array_column($llm->received, 'role'));
    }

    public function test_edit_and_regenerate_check_ownership_and_role(): void
    {
        $owner = User::factory()->create();
        $conversation = $owner->conversations()->create(['title' => 'T']);
        $question = $conversation->messages()->create(['role' => 'user', 'content' => 'q']);
        $reply = $conversation->messages()->create(['role' => 'assistant', 'content' => 'a']);
        $other = User::factory()->create();
        $this->app->instance(LlmClient::class, new ScriptedLlm(['x']));

        $this->actingAs($other)->postJson("/api/messages/{$question->id}/edit", ['content' => 'x'])->assertNotFound();
        $this->actingAs($other)->postJson("/api/messages/{$reply->id}/regenerate")->assertNotFound();
        $this->actingAs($owner)->postJson("/api/messages/{$reply->id}/edit", ['content' => 'x'])->assertNotFound();
        $this->actingAs($owner)->postJson("/api/messages/{$question->id}/regenerate")->assertNotFound();
        $this->actingAs($owner)->postJson("/api/messages/{$question->id}/edit", ['content' => ''])->assertJsonValidationErrors('content');
        $this->assertSame(2, Message::count());
    }

    public function test_text_word_and_pdf_files_are_read_and_sent_to_the_model(): void
    {
        $user = User::factory()->create();

        $llm = $this->chatWith($user, ['content' => 'لخّص', 'file' => UploadedFile::fake()->createWithContent('notes.txt', "\xEF\xBB\xBFسطر أول\r\nسطر ثانٍ")]);
        $message = Message::where('role', 'user')->sole();
        $this->assertSame('notes.txt', $message->file_name);
        $this->assertSame("سطر أول\nسطر ثانٍ", $message->file_text);
        $this->assertSame("[File: notes.txt]\n<document>\nسطر أول\nسطر ثانٍ\n</document>\n\nلخّص", end($llm->received)['content']);
        $this->assertSame('لخّص', Conversation::sole()->title);

        $this->chatWith($user, ['file' => $this->docx('Hello &amp; welcome', 'Second paragraph')]);
        $this->assertSame("Hello & welcome\nSecond paragraph", Message::where('file_name', 'notes.docx')->sole()->file_text);
        $this->assertTrue(Conversation::where('title', 'notes.docx')->exists());

        $this->chatWith($user, ['file' => $this->pdf('Quarterly report total 4200')]);
        $this->assertStringContainsString('Quarterly report total 4200', Message::where('file_name', 'report.pdf')->sole()->file_text);
    }

    public function test_file_text_is_kept_for_follow_up_questions_and_long_files_are_cut(): void
    {
        config(['llm.max_file_chars' => 10]);
        $user = User::factory()->create();
        // One test double for both requests: the route keeps its controller between them.
        $this->app->instance(LlmClient::class, $llm = new ScriptedLlm(['ok']));

        $events = $this->actingAs($user)->post('/api/chat', ['file' => UploadedFile::fake()->createWithContent('long.md', str_repeat('ab', 20))])->streamedContent();
        $this->assertStringContainsString('"file_truncated":true', $events);
        $this->assertSame(str_repeat('ab', 5), Message::where('role', 'user')->sole()->file_text);

        $conversation = Conversation::sole();
        $this->actingAs($user)->post('/api/chat', ['conversation_id' => $conversation->id, 'content' => 'and?'])->streamedContent();
        $this->assertStringContainsString('<document>', $llm->received[1]['content']);
    }

    public function test_unreadable_or_unsupported_files_are_rejected_without_saving(): void
    {
        $user = User::factory()->create();
        $this->app->instance(LlmClient::class, new ScriptedLlm(['x']));

        foreach ([
            UploadedFile::fake()->createWithContent('setup.exe', 'MZ'),
            UploadedFile::fake()->createWithContent('empty.txt', "  \n "),
            UploadedFile::fake()->createWithContent('latin1.txt', "caf\xE9"),
            UploadedFile::fake()->createWithContent('broken.pdf', 'not a pdf'),
            UploadedFile::fake()->createWithContent('broken.docx', 'not a zip'),
        ] as $file) {
            $this->actingAs($user)->postJson('/api/chat', ['file' => $file])->assertJsonValidationErrors('file');
        }

        $this->actingAs($user)->postJson('/api/chat', [
            'image' => UploadedFile::fake()->image('a.png'),
            'file' => UploadedFile::fake()->createWithContent('a.txt', 'x'),
        ])->assertJsonValidationErrors('image');

        $this->assertSame(0, Message::count());
        $this->assertSame(0, Conversation::count());
    }
}
