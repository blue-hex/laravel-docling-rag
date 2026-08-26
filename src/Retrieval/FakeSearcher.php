<?php

namespace BlueHex\DoclingRag\Retrieval;

use BlueHex\DoclingRag\Contracts\SearchesChunks;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Lets host apps exercise their agents without a vector database. Returns
 * canned results and records every search for assertions.
 */
class FakeSearcher implements SearchesChunks
{
    /** @var Collection<int, ChunkResult> */
    protected Collection $results;

    /** @var list<array{query: string, owner: Model, filters: array<string, mixed>, k: ?int}> */
    public array $searches = [];

    /**
     * @param  iterable<int, ChunkResult>  $results
     */
    public function __construct(iterable $results = [])
    {
        $this->results = new Collection($results);
    }

    public function search(string $query, Model $owner, array $filters = [], ?int $k = null): Collection
    {
        $this->searches[] = compact('query', 'owner', 'filters', 'k');

        return $this->results->take($k ?? $this->results->count())->values();
    }
}
