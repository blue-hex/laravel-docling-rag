<?php

namespace BlueHex\DoclingRag\Retrieval;

use BlueHex\DoclingRag\Contracts\RanksChunks;
use Illuminate\Support\Collection;
use Laravel\Ai\Reranking;

/**
 * Reorders the top fused candidates through a cross-encoder reranker. The
 * largest quality gain on real documents, but it costs a provider call — off
 * by default, driven by docling-rag.retrieval.rerank.
 */
class Reranker implements RanksChunks
{
    public function rank(string $query, Collection $results, int $limit): Collection
    {
        $window = $results->take($limit)->values();

        if ($window->count() < 2) {
            return $window;
        }

        $provider = config('docling-rag.retrieval.rerank.provider');
        $model = config('docling-rag.retrieval.rerank.model');

        $response = Reranking::of($window->map(fn (ChunkResult $r): string => $r->text)->all())
            ->limit($limit)
            ->rerank(
                $query,
                is_string($provider) && $provider !== '' ? $provider : null,
                is_string($model) && $model !== '' ? $model : null,
            );

        return (new Collection($response->results))
            ->map(function ($ranked) use ($window): ChunkResult {
                $result = $window[$ranked->index];
                $result->score = $ranked->score;

                return $result;
            })
            ->values();
    }
}
