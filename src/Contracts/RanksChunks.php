<?php

namespace BlueHex\DoclingRag\Contracts;

use BlueHex\DoclingRag\Retrieval\ChunkResult;
use Illuminate\Support\Collection;

interface RanksChunks
{
    /**
     * Rerank fused results against the query, keeping at most $limit.
     *
     * @param  Collection<int, ChunkResult>  $results
     * @return Collection<int, ChunkResult>
     */
    public function rank(string $query, Collection $results, int $limit): Collection;
}
