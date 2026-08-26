<?php

namespace BlueHex\DoclingRag\Embedding;

use BlueHex\DoclingRag\Contracts\EmbedsChunks;
use BlueHex\DoclingRag\Contracts\StoresChunkEmbeddings;
use BlueHex\DoclingRag\Models\RagChunk;
use Illuminate\Support\Collection;
use Laravel\Ai\Embeddings;

class Embedder implements EmbedsChunks
{
    public function __construct(protected StoresChunkEmbeddings $store) {}

    /**
     * @param  Collection<int, RagChunk>  $chunks
     */
    public function embed(Collection $chunks): void
    {
        if ($chunks->isEmpty()) {
            return;
        }

        $dimensions = (int) config('docling-rag.embedding.dimensions', 1536);
        $model = (string) config('docling-rag.embedding.model', 'text-embedding-3-small');
        $provider = config('docling-rag.embedding.provider');

        $texts = $chunks->map(fn (RagChunk $chunk): string => $chunk->text)->values()->all();

        $pending = Embeddings::for($texts)->dimensions($dimensions);

        $response = is_string($provider) && $provider !== ''
            ? $pending->generate($provider, $model)
            : $pending->generate(null, $model);

        foreach ($chunks->values() as $index => $chunk) {
            $this->store->write($chunk, $response->embeddings[$index], $model);
        }
    }
}
