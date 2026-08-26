<?php

namespace BlueHex\DoclingRag\Retrieval;

use BlueHex\DoclingRag\Contracts\EmbedsQueries;
use BlueHex\DoclingRag\Contracts\RanksChunks;
use BlueHex\DoclingRag\Contracts\RetrievesChunks;
use BlueHex\DoclingRag\Contracts\SearchesChunks;
use BlueHex\DoclingRag\Exceptions\ModelMismatchException;
use BlueHex\DoclingRag\Models\RagChunk;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class Searcher implements SearchesChunks
{
    public function __construct(
        protected EmbedsQueries $embedder,
        protected RetrievesChunks $retriever,
        protected RanksChunks $reranker,
    ) {}

    public function search(string $query, Model $owner, array $filters = [], ?int $k = null): Collection
    {
        $query = trim($query);
        $k ??= (int) config('docling-rag.retrieval.k', 8);

        if ($query === '' || $k < 1) {
            return new Collection;
        }

        $this->assertModelMatches($owner);

        $vector = $this->embedder->embed($query);

        $results = $this->retriever->retrieve($vector, $query, $owner, $filters);

        if ($results->isEmpty()) {
            return $results;
        }

        if (config('docling-rag.retrieval.rerank.enabled')) {
            $results = $this->reranker->rank(
                $query,
                $results,
                (int) config('docling-rag.retrieval.rerank.top_n', 30),
            );
        }

        return $this->capPerDocument($results)->take($k)->values();
    }

    /**
     * Drop chunks past the per-document cap while preserving fused order.
     *
     * @param  Collection<int, ChunkResult>  $results
     * @return Collection<int, ChunkResult>
     */
    protected function capPerDocument(Collection $results): Collection
    {
        $cap = (int) config('docling-rag.retrieval.per_document_cap', 3);

        if ($cap < 1) {
            return $results->values();
        }

        $counts = [];

        return $results->filter(function (ChunkResult $result) use (&$counts, $cap): bool {
            $seen = $counts[$result->documentId] ?? 0;

            if ($seen >= $cap) {
                return false;
            }

            $counts[$result->documentId] = $seen + 1;

            return true;
        })->values();
    }

    /**
     * Refuse to search a corpus embedded with a different model. A mismatch
     * means the stored vectors are meaningless against a fresh query vector.
     */
    protected function assertModelMatches(Model $owner): void
    {
        $configured = (string) config('docling-rag.embedding.model', 'text-embedding-3-small');

        $stored = RagChunk::query()
            ->join('rag_documents', 'rag_documents.id', '=', 'rag_chunks.rag_document_id')
            ->where('rag_documents.owner_type', $owner->getMorphClass())
            ->where('rag_documents.owner_id', $owner->getKey())
            ->whereNotNull('rag_chunks.embedded_at')
            ->distinct()
            ->pluck('rag_chunks.embedding_model')
            ->filter();

        foreach ($stored as $model) {
            if ($model !== $configured) {
                throw ModelMismatchException::make($configured, (string) $model);
            }
        }
    }
}
