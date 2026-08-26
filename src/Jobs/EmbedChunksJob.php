<?php

namespace BlueHex\DoclingRag\Jobs;

use BlueHex\DoclingRag\Contracts\EmbedsChunks;
use BlueHex\DoclingRag\Models\RagChunk;
use BlueHex\DoclingRag\Models\RagDocument;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class EmbedChunksJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 60, 120];
    }

    public function __construct(public int $documentId) {}

    public function handle(EmbedsChunks $embedder): void
    {
        $document = RagDocument::query()->findOrFail($this->documentId);

        $batchSize = (int) config('docling-rag.embedding.batch_size', 100);

        $batch = RagChunk::query()
            ->where('rag_document_id', $document->id)
            ->whereNull('embedded_at')
            ->orderBy('ord')
            ->limit($batchSize)
            ->get();

        if ($batch->isEmpty()) {
            $document->markReady();

            return;
        }

        $embedder->embed($batch);

        if (RagChunk::query()->where('rag_document_id', $document->id)->whereNull('embedded_at')->exists()) {
            static::dispatch($document->id);

            return;
        }

        $document->markReady();
    }

    public function failed(?Throwable $exception): void
    {
        RagDocument::query()->find($this->documentId)?->markFailed(
            $exception?->getMessage() ?? 'Embedding job failed.'
        );
    }
}
