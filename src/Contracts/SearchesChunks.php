<?php

namespace BlueHex\DoclingRag\Contracts;

use BlueHex\DoclingRag\Retrieval\ChunkResult;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

interface SearchesChunks
{
    /**
     * Run hybrid search over an owner's corpus.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, ChunkResult>
     */
    public function search(string $query, Model $owner, array $filters = [], ?int $k = null): Collection;
}
