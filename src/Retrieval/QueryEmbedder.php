<?php

namespace BlueHex\DoclingRag\Retrieval;

use BlueHex\DoclingRag\Contracts\EmbedsQueries;
use Illuminate\Support\Facades\Cache;
use Laravel\Ai\Embeddings;

class QueryEmbedder implements EmbedsQueries
{
    public function embed(string $query): array
    {
        $dimensions = (int) config('docling-rag.embedding.dimensions', 1536);
        $model = (string) config('docling-rag.embedding.model', 'text-embedding-3-small');
        $provider = config('docling-rag.embedding.provider');
        $ttl = (int) config('docling-rag.retrieval.cache_ttl', 300);

        $key = 'docling-rag:qembed:'.hash('sha256', $model.':'.$dimensions.':'.$query);

        $resolve = function () use ($query, $dimensions, $model, $provider): array {
            $pending = Embeddings::for([$query])->dimensions($dimensions);

            $response = is_string($provider) && $provider !== ''
                ? $pending->generate($provider, $model)
                : $pending->generate(null, $model);

            return $response->first();
        };

        return $ttl > 0
            ? Cache::remember($key, $ttl, $resolve)
            : $resolve();
    }
}
