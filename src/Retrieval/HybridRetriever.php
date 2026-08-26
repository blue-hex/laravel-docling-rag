<?php

namespace BlueHex\DoclingRag\Retrieval;

use BlueHex\DoclingRag\Contracts\RetrievesChunks;
use BlueHex\DoclingRag\Enums\ContentType;
use BlueHex\DoclingRag\Exceptions\RetrievalException;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Vector + full-text retrieval fused with Reciprocal Rank Fusion, all in one
 * round-trip. Two CTEs rank the same owner-scoped, filtered corpus; a FULL
 * OUTER JOIN sums 1/(k + rank) across both lists. Postgres-only — SQLite has
 * no `<=>` operator to fake.
 */
class HybridRetriever implements RetrievesChunks
{
    public function retrieve(array $vector, string $query, Model $owner, array $filters = []): Collection
    {
        $connection = DB::connection();

        if ($connection->getDriverName() !== 'pgsql') {
            throw new RetrievalException('Hybrid retrieval requires PostgreSQL with pgvector.');
        }

        $this->applyIterativeScan($connection);

        $type = config('docling-rag.embedding.halfvec') ? 'halfvec' : 'vector';
        $language = $this->ftsLanguage();
        $candidates = max(1, (int) config('docling-rag.retrieval.candidates', 50));
        $rrfK = max(1, (int) config('docling-rag.retrieval.rrf_k', 60));

        $qv = json_encode($vector);
        $ownerType = $owner->getMorphClass();
        $ownerId = $owner->getKey();

        [$filterSql, $filterBind] = $this->buildFilters($filters);

        $sql = <<<SQL
        WITH vec AS (
            SELECT c.id,
                   row_number() OVER (ORDER BY c.embedding <=> CAST(? AS {$type})) AS rnk
            FROM rag_chunks c
            JOIN rag_documents d ON d.id = c.rag_document_id
            WHERE d.owner_type = ?
              AND d.owner_id = ?
              AND c.embedding IS NOT NULL{$filterSql}
            ORDER BY c.embedding <=> CAST(? AS {$type})
            LIMIT ?
        ),
        fts AS (
            SELECT c.id,
                   row_number() OVER (ORDER BY ts_rank_cd(c.tsv, q) DESC) AS rnk
            FROM rag_chunks c
            JOIN rag_documents d ON d.id = c.rag_document_id
            CROSS JOIN websearch_to_tsquery(?, ?) AS q
            WHERE d.owner_type = ?
              AND d.owner_id = ?
              AND c.tsv @@ q{$filterSql}
            ORDER BY ts_rank_cd(c.tsv, q) DESC
            LIMIT ?
        ),
        fused AS (
            SELECT COALESCE(vec.id, fts.id) AS id,
                   COALESCE(1.0 / (? + vec.rnk), 0) + COALESCE(1.0 / (? + fts.rnk), 0) AS score
            FROM vec
            FULL OUTER JOIN fts ON vec.id = fts.id
        )
        SELECT c.id, c.rag_document_id, c.text, c.page_no, c.heading_path, c.content_type, f.score
        FROM fused f
        JOIN rag_chunks c ON c.id = f.id
        ORDER BY f.score DESC
        LIMIT ?
        SQL;

        $bindings = [
            $qv, $ownerType, $ownerId, ...$filterBind, $qv, $candidates,
            $language, $query, $ownerType, $ownerId, ...$filterBind, $candidates,
            $rrfK, $rrfK,
            $candidates * 2,
        ];

        return (new Collection($connection->select($sql, $bindings)))
            ->map(fn ($row): ChunkResult => ChunkResult::fromRow($row));
    }

    /**
     * Build the owner-scoped filter fragment shared by both CTEs. Every clause
     * here is spliced into the vector and full-text branches identically, so
     * the bindings it returns are consumed once per branch.
     *
     * @param  array<string, mixed>  $filters
     * @return array{0: string, 1: list<mixed>}
     */
    protected function buildFilters(array $filters): array
    {
        $sql = '';
        $bindings = [];

        $contentType = $filters['content_type'] ?? null;

        if ($contentType instanceof ContentType) {
            $contentType = $contentType->value;
        }

        if (is_string($contentType) && $contentType !== '') {
            $sql .= ' AND c.content_type = ?';
            $bindings[] = $contentType;
        }

        $documentIds = $this->normalizeDocumentIds($filters['document_ids'] ?? null);

        if ($documentIds !== []) {
            $placeholders = implode(', ', array_fill(0, count($documentIds), '?'));
            $sql .= " AND d.id IN ({$placeholders})";
            $bindings = [...$bindings, ...$documentIds];
        }

        return [$sql, $bindings];
    }

    /**
     * @return list<int>
     */
    protected function normalizeDocumentIds(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        return (new Collection(is_array($value) ? $value : [$value]))
            ->filter(fn ($id): bool => is_numeric($id))
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    protected function applyIterativeScan(Connection $connection): void
    {
        $mode = (string) config('docling-rag.retrieval.iterative_scan', 'relaxed_order');

        if (! in_array($mode, ['off', 'relaxed_order', 'strict_order'], true)) {
            return;
        }

        $connection->statement("SET hnsw.iterative_scan = {$mode}");
    }

    protected function ftsLanguage(): string
    {
        $language = (string) config('docling-rag.fts.language', 'english');
        $allowed = [
            'english', 'simple', 'german', 'french', 'spanish', 'italian',
            'dutch', 'portuguese', 'russian', 'swedish', 'norwegian',
            'danish', 'finnish', 'hungarian', 'turkish',
        ];

        return in_array($language, $allowed, true) ? $language : 'english';
    }
}
