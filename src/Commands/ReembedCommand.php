<?php

namespace BlueHex\DoclingRag\Commands;

use BlueHex\DoclingRag\Enums\DocumentStatus;
use BlueHex\DoclingRag\Jobs\EmbedChunksJob;
use BlueHex\DoclingRag\Models\RagChunk;
use BlueHex\DoclingRag\Models\RagDocument;
use Illuminate\Console\Command;

class ReembedCommand extends Command
{
    public $signature = 'rag:reembed {document? : rag_documents id to re-embed}';

    public $description = 'Re-embed chunks whose model no longer matches config';

    public function handle(): int
    {
        $model = (string) config('docling-rag.embedding.model');
        $documentId = $this->argument('document');

        $query = RagChunk::query();

        if ($documentId !== null) {
            $query->where('rag_document_id', $documentId);
        } else {
            $query->where(function ($builder) use ($model): void {
                $builder->whereNull('embedding_model')
                    ->orWhere('embedding_model', '!=', $model);
            });
        }

        $documentIds = $query->pluck('rag_document_id')->unique()->filter()->values();

        if ($documentIds->isEmpty()) {
            $this->info('Nothing to re-embed.');

            return self::SUCCESS;
        }

        RagChunk::query()
            ->whereIn('rag_document_id', $documentIds)
            ->update([
                'embedded_at' => null,
            ]);

        RagDocument::query()
            ->whereIn('id', $documentIds)
            ->update([
                'status' => DocumentStatus::Embedding,
                'failure_reason' => null,
            ]);

        foreach ($documentIds as $id) {
            EmbedChunksJob::dispatch((int) $id);
        }

        $this->info('Queued re-embed jobs for '.$documentIds->count().' document(s).');

        return self::SUCCESS;
    }
}
