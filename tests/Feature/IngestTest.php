<?php

use BlueHex\DoclingRag\Enums\DocumentStatus;
use BlueHex\DoclingRag\Facades\Rag;
use BlueHex\DoclingRag\Jobs\IngestDocumentJob;
use BlueHex\DoclingRag\Models\RagDocument;
use BlueHex\DoclingRag\Tests\Fixtures\DataSource;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

it('stores the file and dispatches ingestion', function () {
    Queue::fake();

    $owner = DataSource::query()->create(['name' => 'ds-1']);
    $file = UploadedFile::fake()->create('report.pdf', 20, 'application/pdf');

    $document = Rag::ingest($file, owner: $owner);

    expect($document->status)->toBe(DocumentStatus::Pending)
        ->and($document->owner_id)->toBe($owner->id)
        ->and(Storage::disk('local')->exists($document->path))->toBeTrue();

    Queue::assertPushed(IngestDocumentJob::class);
});

it('is idempotent for the same owner and sha256', function () {
    Queue::fake();

    $owner = DataSource::query()->create(['name' => 'ds-1']);
    $path = sys_get_temp_dir().'/rag-report.pdf';
    file_put_contents($path, 'same-bytes');

    $first = Rag::ingest($path, owner: $owner);
    $second = Rag::ingest($path, owner: $owner);

    expect($second->id)->toBe($first->id)
        ->and(RagDocument::query()->count())->toBe(1);

    Queue::assertPushed(IngestDocumentJob::class, 1);

    @unlink($path);
});

it('re-dispatches a previously failed document', function () {
    Queue::fake();

    $owner = DataSource::query()->create(['name' => 'ds-1']);
    $path = sys_get_temp_dir().'/rag-failed.pdf';
    file_put_contents($path, 'failed-bytes');
    $sha256 = hash('sha256', 'failed-bytes');

    $failed = RagDocument::factory()->failed()->create([
        'owner_type' => $owner->getMorphClass(),
        'owner_id' => $owner->id,
        'sha256' => $sha256,
    ]);

    $document = Rag::ingest($path, owner: $owner);

    expect($document->id)->toBe($failed->id)
        ->and($document->status)->toBe(DocumentStatus::Pending)
        ->and($document->failure_reason)->toBeNull();

    Queue::assertPushed(IngestDocumentJob::class);

    @unlink($path);
});

it('exposes rag documents through the host trait', function () {
    Queue::fake();

    $owner = DataSource::query()->create(['name' => 'ds-1']);
    $file = UploadedFile::fake()->create('notes.md', 5, 'text/markdown');

    $document = $owner->ingestDocument($file);

    expect($owner->ragDocuments)->toHaveCount(1)
        ->and($document->path)->toEndWith('notes.md');
});
