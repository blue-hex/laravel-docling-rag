<?php

namespace BlueHex\DoclingRag\Contracts;

use BlueHex\DoclingRag\Retrieval\ChunkResult;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

interface RetrievesChunks
{
    /**
     * Retrieve owner-scoped chunks fused from vector and full-text search.
     *
     * @param  list<float>  $vector
     * @param  array<string, mixed>  $filters
     * @return Collection<int, ChunkResult>
     */
    public function retrieve(array $vector, string $query, Model $owner, array $filters = []): Collection;
}
