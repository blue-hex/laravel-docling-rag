<?php

namespace BlueHex\DoclingRag\Embedding;

use BlueHex\DoclingRag\Contracts\StoresChunkEmbeddings;
use BlueHex\DoclingRag\Models\RagChunk;
use Illuminate\Support\Facades\DB;

class ChunkStore implements StoresChunkEmbeddings
{
    public function write(RagChunk $chunk, array $embedding, string $model): void
    {
        $now = now();

        if (DB::connection()->getDriverName() === 'pgsql') {
            $type = config('docling-rag.embedding.halfvec') ? 'halfvec' : 'vector';

            DB::statement(
                "UPDATE rag_chunks SET embedding = ?::{$type}, embedding_model = ?, embedded_at = ? WHERE id = ?",
                [json_encode($embedding), $model, $now, $chunk->id]
            );

            return;
        }

        $chunk->forceFill([
            'embedding' => $embedding,
            'embedding_model' => $model,
            'embedded_at' => $now,
        ])->save();
    }
}
