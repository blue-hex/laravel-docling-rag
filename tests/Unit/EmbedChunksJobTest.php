<?php

use BlueHex\DoclingRag\Contracts\EmbedsChunks;
use BlueHex\DoclingRag\Enums\DocumentStatus;
use BlueHex\DoclingRag\Events\DocumentIngested;
use BlueHex\DoclingRag\Jobs\EmbedChunksJob;
use BlueHex\DoclingRag\Models\RagChunk;
use BlueHex\DoclingRag\Models\RagDocument;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

it('embeds only chunks that are missing embedded_at', function () {
    Event::fake([DocumentIngested::class]);

    $document = RagDocument::factory()->create([
        'status' => DocumentStatus::Embedding,
    ]);

    RagChunk::factory()->embedded()->create([
        'rag_document_id' => $document->id,
        'ord' => 0,
        'text' => 'already done',
    ]);

    RagChunk::factory()->create([
        'rag_document_id' => $document->id,
        'ord' => 1,
        'text' => 'needs embedding',
        'embedded_at' => null,
    ]);

    (new EmbedChunksJob($document->id))->handle(app(EmbedsChunks::class));

    $pending = $document->chunks()->whereNull('embedded_at')->count();

    expect($pending)->toBe(0)
        ->and($document->refresh()->status)->toBe(DocumentStatus::Ready);

    Event::assertDispatched(DocumentIngested::class);
});

it('re-dispatches when a batch still has unembedded chunks', function () {
    Queue::fake();
    config(['docling-rag.embedding.batch_size' => 1]);

    $document = RagDocument::factory()->create([
        'status' => DocumentStatus::Embedding,
    ]);

    RagChunk::factory()->count(2)->create([
        'rag_document_id' => $document->id,
        'embedded_at' => null,
    ]);

    (new EmbedChunksJob($document->id))->handle(app(EmbedsChunks::class));

    Queue::assertPushed(EmbedChunksJob::class);
    expect($document->refresh()->status)->toBe(DocumentStatus::Embedding)
        ->and($document->chunks()->whereNull('embedded_at')->count())->toBe(1);
});
