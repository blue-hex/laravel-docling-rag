<?php

use BlueHex\DoclingRag\Contracts\ChunksDocuments;
use BlueHex\DoclingRag\Contracts\MapsChunks;
use BlueHex\DoclingRag\Docling\ChunkMapper;
use BlueHex\DoclingRag\Docling\DoclingTask;
use BlueHex\DoclingRag\Enums\DocumentStatus;
use BlueHex\DoclingRag\Events\IngestionFailed;
use BlueHex\DoclingRag\Jobs\EmbedChunksJob;
use BlueHex\DoclingRag\Jobs\PollDoclingTaskJob;
use BlueHex\DoclingRag\Models\RagDocument;
use BlueHex\DoclingRag\Tests\Fixtures\DoclingResponses;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

it('re-dispatches with a delay while Docling is still running', function () {
    Queue::fake();
    config(['docling-rag.docling.poll.max_attempts' => 5]);

    $this->mock(ChunksDocuments::class, function ($mock) {
        $mock->shouldReceive('poll')
            ->once()
            ->andReturn(new DoclingTask('task-1', 'pending'));
    });

    $document = RagDocument::factory()->create([
        'status' => DocumentStatus::Chunking,
        'docling_task_id' => 'task-1',
    ]);

    (new PollDoclingTaskJob($document->id, 0))->handle(
        app(ChunksDocuments::class),
        app(MapsChunks::class),
    );

    Queue::assertPushed(PollDoclingTaskJob::class, function (PollDoclingTaskJob $job) {
        return $job->attempt === 1 && $job->delay !== null;
    });

    expect($document->refresh()->status)->toBe(DocumentStatus::Chunking);
});

it('stores mapped chunks and dispatches embedding when Docling succeeds', function () {
    Queue::fake();

    $this->mock(ChunksDocuments::class, function ($mock) {
        $mock->shouldReceive('poll')->once()->andReturn(new DoclingTask('task-1', 'success'));
        $mock->shouldReceive('result')->once()->andReturn(DoclingResponses::result());
    });

    $document = RagDocument::factory()->create([
        'status' => DocumentStatus::Chunking,
        'docling_task_id' => 'task-1',
    ]);

    (new PollDoclingTaskJob($document->id, 0))->handle(
        app(ChunksDocuments::class),
        new ChunkMapper,
    );

    $document->refresh();

    expect($document->status)->toBe(DocumentStatus::Embedding)
        ->and($document->chunks)->toHaveCount(3)
        ->and($document->page_count)->toBe(3);

    Queue::assertPushed(EmbedChunksJob::class);
});

it('fails fast when the page cap is exceeded', function () {
    Event::fake([IngestionFailed::class]);

    $this->mock(ChunksDocuments::class, function ($mock) {
        $mock->shouldReceive('poll')->once()->andReturn(new DoclingTask('task-1', 'success'));
        $mock->shouldReceive('result')->once()->andReturn(DoclingResponses::result());
    });

    config(['docling-rag.limits.max_pages' => 1]);

    $document = RagDocument::factory()->create([
        'status' => DocumentStatus::Chunking,
        'docling_task_id' => 'task-1',
    ]);

    (new PollDoclingTaskJob($document->id, 0))->handle(
        app(ChunksDocuments::class),
        new ChunkMapper,
    );

    expect($document->refresh()->status)->toBe(DocumentStatus::Failed)
        ->and($document->failure_reason)->toContain('pages')
        ->and($document->chunks)->toHaveCount(0);

    Event::assertDispatched(IngestionFailed::class);
});

it('fails fast when the chunk cap is exceeded', function () {
    $this->mock(ChunksDocuments::class, function ($mock) {
        $mock->shouldReceive('poll')->once()->andReturn(new DoclingTask('task-1', 'success'));
        $mock->shouldReceive('result')->once()->andReturn(DoclingResponses::result());
    });

    config(['docling-rag.limits.max_chunks' => 1]);

    $document = RagDocument::factory()->create([
        'status' => DocumentStatus::Chunking,
        'docling_task_id' => 'task-1',
    ]);

    (new PollDoclingTaskJob($document->id, 0))->handle(
        app(ChunksDocuments::class),
        new ChunkMapper,
    );

    expect($document->refresh()->status)->toBe(DocumentStatus::Failed)
        ->and($document->failure_reason)->toContain('chunks');
});

it('marks the document failed after the poll budget is exhausted', function () {
    config(['docling-rag.docling.poll.max_attempts' => 2]);

    $this->mock(ChunksDocuments::class, function ($mock) {
        $mock->shouldReceive('poll')->once()->andReturn(new DoclingTask('task-1', 'started'));
    });

    $document = RagDocument::factory()->create([
        'status' => DocumentStatus::Chunking,
        'docling_task_id' => 'task-1',
    ]);

    (new PollDoclingTaskJob($document->id, 1))->handle(
        app(ChunksDocuments::class),
        app(MapsChunks::class),
    );

    expect($document->refresh()->status)->toBe(DocumentStatus::Failed)
        ->and($document->failure_reason)->toContain('timed out');
});
