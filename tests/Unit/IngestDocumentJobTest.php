<?php

use BlueHex\DoclingRag\Contracts\ChunksDocuments;
use BlueHex\DoclingRag\Contracts\ConvertsUnsupportedFormats;
use BlueHex\DoclingRag\Contracts\RoutesFormats;
use BlueHex\DoclingRag\Conversion\ConvertedFile;
use BlueHex\DoclingRag\Docling\DoclingTask;
use BlueHex\DoclingRag\Enums\ConvertedVia;
use BlueHex\DoclingRag\Enums\DocumentStatus;
use BlueHex\DoclingRag\Exceptions\GotenbergException;
use BlueHex\DoclingRag\Jobs\IngestDocumentJob;
use BlueHex\DoclingRag\Jobs\PollDoclingTaskJob;
use BlueHex\DoclingRag\Models\RagDocument;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

it('submits native files to Docling and dispatches a poll job', function () {
    Queue::fake();

    $this->mock(ChunksDocuments::class, function ($mock) {
        $mock->shouldReceive('submit')
            ->once()
            ->andReturn(new DoclingTask('task-1', 'pending'));
    });

    $document = RagDocument::factory()->create([
        'path' => 'rag/1/abc/report.pdf',
        'status' => DocumentStatus::Pending,
    ]);

    Storage::disk('local')->put($document->path, '%PDF-1.4');

    (new IngestDocumentJob($document->id))->handle(
        app(RoutesFormats::class),
        app(ConvertsUnsupportedFormats::class),
        app(ChunksDocuments::class),
    );

    $document->refresh();

    expect($document->status)->toBe(DocumentStatus::Chunking)
        ->and($document->converted_via)->toBe(ConvertedVia::Native)
        ->and($document->docling_task_id)->toBe('task-1');

    Queue::assertPushed(PollDoclingTaskJob::class);
});

it('sends .markdown files to Docling renamed to .md', function () {
    Queue::fake();

    $this->mock(ChunksDocuments::class, function ($mock) {
        $mock->shouldReceive('submit')
            ->once()
            ->withArgs(fn ($filename) => $filename === 'notes.md')
            ->andReturn(new DoclingTask('task-4', 'pending'));
    });

    $document = RagDocument::factory()->create([
        'path' => 'rag/1/abc/notes.markdown',
        'status' => DocumentStatus::Pending,
    ]);

    Storage::disk('local')->put($document->path, '# notes');

    (new IngestDocumentJob($document->id))->handle(
        app(RoutesFormats::class),
        app(ConvertsUnsupportedFormats::class),
        app(ChunksDocuments::class),
    );

    expect($document->refresh()->docling_task_id)->toBe('task-4');
});

it('forwards per-ingest Docling options to submit', function () {
    Queue::fake();

    $this->mock(ChunksDocuments::class, function ($mock) {
        $mock->shouldReceive('submit')
            ->once()
            ->withArgs(fn ($filename, $contents, $options) => $options === ['convert_do_ocr' => false])
            ->andReturn(new DoclingTask('task-3', 'pending'));
    });

    $document = RagDocument::factory()->create([
        'path' => 'rag/1/abc/scan.png',
        'status' => DocumentStatus::Pending,
    ]);

    Storage::disk('local')->put($document->path, 'png-bytes');

    (new IngestDocumentJob($document->id, ['convert_do_ocr' => false]))->handle(
        app(RoutesFormats::class),
        app(ConvertsUnsupportedFormats::class),
        app(ChunksDocuments::class),
    );

    expect($document->refresh()->docling_task_id)->toBe('task-3');
});

it('converts through Gotenberg when the format is not native', function () {
    config(['docling-rag.gotenberg.enabled' => true]);
    Queue::fake();

    $this->mock(ConvertsUnsupportedFormats::class, function ($mock) {
        $mock->shouldReceive('convert')
            ->once()
            ->andReturn(new ConvertedFile('memo.pdf', '%PDF-converted'));
    });

    $this->mock(ChunksDocuments::class, function ($mock) {
        $mock->shouldReceive('submit')
            ->once()
            ->with('memo.pdf', '%PDF-converted', [])
            ->andReturn(new DoclingTask('task-2', 'pending'));
    });

    $document = RagDocument::factory()->create([
        'path' => 'rag/1/abc/memo.rtf',
        'mime' => 'application/rtf',
        'status' => DocumentStatus::Pending,
    ]);

    Storage::disk('local')->put($document->path, 'rtf-bytes');

    (new IngestDocumentJob($document->id))->handle(
        app(RoutesFormats::class),
        app(ConvertsUnsupportedFormats::class),
        app(ChunksDocuments::class),
    );

    expect($document->refresh()->converted_via)->toBe(ConvertedVia::Gotenberg);
});

it('fails the document without contacting Docling when the file exceeds the upload limit', function () {
    config(['docling-rag.limits.max_upload_mb' => 1]);

    $this->mock(ChunksDocuments::class, function ($mock) {
        $mock->shouldNotReceive('submit');
    });

    $document = RagDocument::factory()->create([
        'path' => 'rag/1/abc/huge.pdf',
        'status' => DocumentStatus::Pending,
    ]);

    Storage::disk('local')->put($document->path, str_repeat('a', 2 * 1024 * 1024));

    (new IngestDocumentJob($document->id))->handle(
        app(RoutesFormats::class),
        app(ConvertsUnsupportedFormats::class),
        app(ChunksDocuments::class),
    );

    $document->refresh();

    expect($document->status)->toBe(DocumentStatus::Failed)
        ->and($document->failure_reason)->toContain('limit is 1 MB');
});

it('fails the document when Gotenberg returns a ZIP', function () {
    config(['docling-rag.gotenberg.enabled' => true]);

    $this->mock(ConvertsUnsupportedFormats::class, function ($mock) {
        $mock->shouldReceive('convert')
            ->once()
            ->andThrow(new GotenbergException('Gotenberg returned a ZIP archive; v1 supports a single file only.'));
    });

    $document = RagDocument::factory()->create([
        'path' => 'rag/1/abc/memo.rtf',
        'status' => DocumentStatus::Pending,
    ]);

    Storage::disk('local')->put($document->path, 'rtf-bytes');

    (new IngestDocumentJob($document->id))->handle(
        app(RoutesFormats::class),
        app(ConvertsUnsupportedFormats::class),
        app(ChunksDocuments::class),
    );

    expect($document->refresh()->status)->toBe(DocumentStatus::Failed)
        ->and($document->failure_reason)->toContain('ZIP');
});
