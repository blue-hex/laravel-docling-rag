<?php

use BlueHex\DoclingRag\Enums\DocumentStatus;
use BlueHex\DoclingRag\Jobs\EmbedChunksJob;
use BlueHex\DoclingRag\Models\RagChunk;
use BlueHex\DoclingRag\Models\RagDocument;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

it('publishes config and migrations', function () {
    $this->artisan('rag:install')->assertSuccessful();
});

it('fails index creation on sqlite', function () {
    $this->artisan('rag:index')
        ->assertFailed()
        ->expectsOutputToContain('PostgreSQL');
});

it('reports an unhealthy stack on sqlite without Docling', function () {
    Http::fake([
        'docling.test/health' => Http::response('down', 500),
    ]);

    $this->artisan('rag:health')->assertFailed();
});

it('queues re-embed jobs for drifted models', function () {
    Queue::fake();

    $document = RagDocument::factory()->ready()->create();

    RagChunk::factory()->create([
        'rag_document_id' => $document->id,
        'embedding_model' => 'old-model',
        'embedded_at' => now(),
    ]);

    $this->artisan('rag:reembed')->assertSuccessful();

    expect($document->chunks()->first()->embedded_at)->toBeNull()
        ->and($document->refresh()->status)->toBe(DocumentStatus::Embedding);

    Queue::assertPushed(EmbedChunksJob::class);
});
