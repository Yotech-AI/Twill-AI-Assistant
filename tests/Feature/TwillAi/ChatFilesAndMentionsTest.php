<?php

use A17\Twill\Models\Media;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Files\Image;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use TwillAi\Exceptions\TwillAiException;
use TwillAi\Jobs\RunTwillAiChat;
use TwillAi\Models\Chat;
use TwillAi\Models\ChatFile;
use TwillAi\Services\ChatAttachmentResolver;
use TwillAi\Services\MediaIngestionService;
use TwillAi\Services\ModuleRegistry;
use TwillAi\Services\PayloadBuilder;
use TwillAi\Tests\Fixtures\Models\Article;

beforeEach(function () {
    Storage::fake('twill-ai');
});

function filesTestChat(object $user): Chat
{
    return Chat::create(['user_id' => $user->id, 'provider' => 'anthropic', 'model' => 'claude-sonnet-4-6']);
}

/**
 * Seed one entry of the fixture "articles" module through the package's own
 * payload builder + repository, i.e. exactly the path the agent writes on.
 */
function seedArticle(string $title = 'Focus Without Interruptions'): Article
{
    $fields = app(PayloadBuilder::class)->buildForCreate('articles', [
        'fields' => ['title' => ['en' => $title]],
        'locales' => ['en'],
    ]);

    return app(ModuleRegistry::class)->repository('articles')->create($fields);
}

/* ---------- Library uploads ---------- */

it('stores an upload in the shared library on the private disk', function () {
    $user = twillAdmin('files@example.com');

    $response = $this->actingAs($user, 'twill_users')
        ->post(twillAiUrl('files'), ['files' => [UploadedFile::fake()->image('hero.png')]])
        ->assertCreated()
        ->json();

    expect($response['files'])->toHaveCount(1);

    $meta = $response['files'][0];
    expect($meta['name'])->toBe('hero.png')
        ->and($meta['is_image'])->toBeTrue()
        ->and($meta['preview_url'])->toContain(twillAiUrl('files/'));

    $file = ChatFile::firstOrFail();
    expect($file->chat_id)->toBeNull()
        ->and($file->user_id)->toBe($user->id)
        ->and($file->disk)->toBe('twill-ai');

    Storage::disk('twill-ai')->assertExists($file->path);
});

it('rejects disallowed file types and oversized files', function () {
    $user = twillAdmin('files-reject@example.com');

    $this->actingAs($user, 'twill_users')
        ->post(twillAiUrl('files'), ['files' => [UploadedFile::fake()->create('evil.exe', 10)]], ['Accept' => 'application/json'])
        ->assertStatus(422);

    $this->actingAs($user, 'twill_users')
        ->post(twillAiUrl('files'), ['files' => [UploadedFile::fake()->create('huge.pdf', 30000)]], ['Accept' => 'application/json'])
        ->assertStatus(422);

    expect(ChatFile::count())->toBe(0);
});

it('serves a library file to any admin and never publicly', function () {
    $owner = twillAdmin('owner-file@example.com');
    $other = twillAdmin('other-file@example.com');

    $fileId = $this->actingAs($owner, 'twill_users')
        ->post(twillAiUrl('files'), ['files' => [UploadedFile::fake()->image('a.png')]])
        ->json('files.0.id');

    $this->actingAs($owner, 'twill_users')
        ->get(twillAiUrl("files/{$fileId}"))
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    // Shared library: a different admin can read the same file.
    $this->actingAs($other, 'twill_users')
        ->get(twillAiUrl("files/{$fileId}"))
        ->assertOk();

    // Guests cannot reach the file, and the disk exposes no public URL.
    auth('twill_users')->logout();
    $this->get(twillAiUrl("files/{$fileId}"))->assertRedirect();

    expect(config('filesystems.disks.twill-ai.url'))->toBeNull();
});

it('lists the shared library to any admin', function () {
    $uploader = twillAdmin('uploader@example.com');

    $this->actingAs($uploader, 'twill_users')
        ->post(twillAiUrl('files'), ['files' => [UploadedFile::fake()->image('shared.png')]])
        ->assertCreated();

    $items = $this->actingAs(twillAdmin('viewer@example.com'), 'twill_users')
        ->getJson(twillAiUrl('files'))
        ->assertOk()
        ->json('files');

    expect($items)->toHaveCount(1);
    expect($items[0]['name'])->toBe('shared.png')
        ->and($items[0])->toHaveKey('uploaded_by');
});

it('keeps library files when a chat is deleted and removes them via the files endpoint', function () {
    $user = twillAdmin('files-keep@example.com');
    $chat = filesTestChat($user);

    $fileId = $this->actingAs($user, 'twill_users')
        ->post(twillAiUrl('files'), ['files' => [UploadedFile::fake()->image('a.png')]])
        ->json('files.0.id');

    $path = ChatFile::findOrFail($fileId)->path;

    // Deleting a chat must NOT remove shared library files.
    $this->actingAs($user, 'twill_users')
        ->deleteJson(twillAiUrl("chats/{$chat->id}"))
        ->assertOk();

    expect(ChatFile::count())->toBe(1);
    Storage::disk('twill-ai')->assertExists($path);

    // The files endpoint removes the record and its bytes.
    $this->actingAs($user, 'twill_users')
        ->deleteJson(twillAiUrl("files/{$fileId}"))
        ->assertOk();

    expect(ChatFile::count())->toBe(0);
    Storage::disk('twill-ai')->assertMissing($path);
});

/* ---------- Mentions ---------- */

it('lists mentionable content as module + title and filters by query', function () {
    $user = twillAdmin('mentions@example.com');
    seedArticle('Deep Work in a Noisy House');

    $items = $this->actingAs($user, 'twill_users')
        ->getJson(twillAiUrl('mentionables?q=Noisy'))
        ->assertOk()
        ->json('items');

    expect($items)->not->toBeEmpty();

    $match = collect($items)->firstWhere('title', 'Deep Work in a Noisy House');
    expect($match)->not->toBeNull()
        ->and($match['module'])->toBe('articles')
        ->and($match['module_label'])->toBe('Articles')
        ->and($match)->toHaveKey('edit_url');
});

it('requires authentication for the mentionables endpoint', function () {
    $this->getJson(twillAiUrl('mentionables'))->assertStatus(401);
});

/* ---------- queueMessage wiring ---------- */

it('passes existing attachments and valid mentions to the queued job', function () {
    Queue::fake();

    $user = twillAdmin('queue-wiring@example.com');
    $chat = filesTestChat($user);
    $article = seedArticle();

    $file = ChatFile::create([
        'chat_id' => null,
        'user_id' => $user->id,
        'disk' => 'twill-ai',
        'path' => 'lib/doc.txt',
        'original_name' => 'doc.txt',
        'mime' => 'text/plain',
        'size' => 10,
    ]);

    $this->actingAs($user, 'twill_users')
        ->postJson(twillAiUrl("chats/{$chat->id}/messages"), [
            'message' => 'Use these',
            'attachments' => [$file->id, 99999], // 99999 does not exist and must be dropped
            'mentions' => [
                ['module' => 'articles', 'id' => $article->id],
                ['module' => 'articles', 'id' => 88888], // missing entry, dropped
            ],
        ])
        ->assertStatus(202);

    Queue::assertPushed(RunTwillAiChat::class, function (RunTwillAiChat $job) use ($file, $article) {
        return $job->attachmentIds === [$file->id]
            && $job->mentions === [['module' => 'articles', 'id' => $article->id]];
    });
});

/* ---------- Prompt assembly (the job) ---------- */

it('injects mentioned entries and text-file content into the prompt, attaching images natively', function () {
    $user = twillAdmin('prompt-assembly@example.com');
    $chat = filesTestChat($user);
    $article = seedArticle('Round Trip');

    Storage::disk('twill-ai')->put('lib/notes.txt', 'Secret content from the attached file.');

    $textFile = ChatFile::create([
        'chat_id' => null, 'user_id' => $user->id, 'disk' => 'twill-ai',
        'path' => 'lib/notes.txt', 'original_name' => 'notes.txt', 'mime' => 'text/plain', 'size' => 40,
    ]);
    $imageFile = ChatFile::create([
        'chat_id' => null, 'user_id' => $user->id, 'disk' => 'twill-ai',
        'path' => 'lib/pic.png', 'original_name' => 'pic.png', 'mime' => 'image/png', 'size' => 100,
    ]);

    $job = new RunTwillAiChat($chat->id, $user->id, 'What is in here?', [$textFile->id, $imageFile->id], [
        ['module' => 'articles', 'id' => $article->id],
    ]);

    $method = new ReflectionMethod($job, 'buildPrompt');
    $method->setAccessible(true);
    [$prompt, $attachments] = $method->invoke($job);

    expect($prompt)->toContain('Round Trip')                  // the @-mentioned entry title
        ->toContain('module "articles"')
        ->toContain('Secret content from the attached file.') // text-file content injected
        ->toContain('What is in here?')                       // the user's message
        ->toContain("file_id {$imageFile->id}");              // image listed for use_attachment_as_media

    // The image is sent natively; the text file is NOT an attachment.
    expect($attachments)->toHaveCount(1)
        ->and($attachments[0])->toBeInstanceOf(Image::class);
});

/* ---------- Attachment resolver ---------- */

it('classifies attachments and extracts docx text', function () {
    $chat = filesTestChat(twillAdmin('docx@example.com'));

    // A real .docx generated with PhpWord.
    $phpWord = new PhpWord;
    $section = $phpWord->addSection();
    $section->addText('Hello from the Word document.');
    $tmp = tempnam(sys_get_temp_dir(), 'tai').'.docx';
    IOFactory::createWriter($phpWord, 'Word2007')->save($tmp);
    Storage::disk('twill-ai')->put("{$chat->id}/brief.docx", file_get_contents($tmp));
    @unlink($tmp);

    $docx = ChatFile::create([
        'chat_id' => null, 'user_id' => $chat->user_id, 'disk' => 'twill-ai',
        'path' => "{$chat->id}/brief.docx", 'original_name' => 'brief.docx',
        'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'size' => 100,
    ]);
    $pdf = ChatFile::create([
        'chat_id' => null, 'user_id' => $chat->user_id, 'disk' => 'twill-ai',
        'path' => "{$chat->id}/spec.pdf", 'original_name' => 'spec.pdf', 'mime' => 'application/pdf', 'size' => 100,
    ]);

    $resolved = app(ChatAttachmentResolver::class)->resolve([$docx, $pdf]);

    expect($resolved['attachments'])->toHaveCount(1)
        ->and($resolved['attachments'][0])->toBeInstanceOf(Document::class)
        ->and($resolved['texts'])->toHaveCount(1)
        ->and($resolved['texts'][0]['name'])->toBe('brief.docx')
        ->and($resolved['texts'][0]['text'])->toContain('Hello from the Word document.');
});

/* ---------- Media ingestion ---------- */

it('ingests an attachment image into the Twill media library and is idempotent', function () {
    Storage::fake('twill_media_library');

    $chat = filesTestChat(twillAdmin('ingest@example.com'));

    $gd = imagecreatetruecolor(120, 90);
    ob_start();
    imagepng($gd);
    $bytes = (string) ob_get_clean();
    imagedestroy($gd);
    Storage::disk('twill-ai')->put('lib/pic.png', $bytes);

    $file = ChatFile::create([
        'chat_id' => null, 'user_id' => $chat->user_id, 'disk' => 'twill-ai',
        'path' => 'lib/pic.png', 'original_name' => 'pic.png', 'mime' => 'image/png', 'size' => 100,
    ]);

    $result = app(MediaIngestionService::class)->ingest($file, 'A picture');

    expect($result['media_id'])->toBeGreaterThan(0)
        ->and($result['width'])->toBe(120)
        ->and($result['height'])->toBe(90);

    $media = Media::findOrFail($result['media_id']);
    expect($media->filename)->toBe('pic.png')
        ->and($media->alt_text)->toBe('A picture');
    Storage::disk('twill_media_library')->assertExists($media->uuid);

    expect($file->fresh()->media_id)->toBe($media->id);

    // Idempotent: a second call returns the same media, creating no duplicate.
    $again = app(MediaIngestionService::class)->ingest($file->fresh());
    expect($again['media_id'])->toBe($result['media_id']);
    expect(Media::count())->toBe(1);
});

it('refuses to add a non-image attachment to the media library', function () {
    $chat = filesTestChat(twillAdmin('non-image@example.com'));

    $file = ChatFile::create([
        'chat_id' => null, 'user_id' => $chat->user_id, 'disk' => 'twill-ai',
        'path' => 'lib/doc.txt', 'original_name' => 'doc.txt', 'mime' => 'text/plain', 'size' => 10,
    ]);

    app(MediaIngestionService::class)->ingest($file);
})->throws(TwillAiException::class);
