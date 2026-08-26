# Unstructured Data Analysis Package — Design

Date: 2026-08-13
Status: Approved design, pre-implementation

An internal, reusable Laravel package that turns uploaded documents (PDF,
Office, HTML) into retrievable, cited chunks backed by pgvector, and exposes
hybrid search to any `laravel/ai` agent.

## Goal

Give every internal Laravel project the same document-RAG capability without
re-solving conversion, chunking, embedding, index tuning, and hybrid ranking.
The package owns the hard, portable parts. Each host app keeps its own domain
models, tenancy, auth, billing, and UI.

## Scope

This design covers two specs. They ship in order.

| Spec | Contents | Ships when |
|---|---|---|
| 1 — Ingestion | route → convert → chunk → embed → store | Rows land in `rag_chunks` with correct provenance |
| 2 — Retrieval | hybrid search, RRF, rerank, `SearchDocuments` tool | Search returns cited chunks to an agent |

Spec 1 is independently verifiable and must land first. Spec 2 has nothing to
query without it.

Out of scope for v1: multi-language FTS configurations, parent-child
retrieval, multi-file Gotenberg batches, and any UI.

## Decisions

These were settled during design. Revisit them deliberately, not casually.

1. **Engine + contracts, not a vertical.** The package owns conversion,
   chunking, embedding, storage, and retrieval. The host owns its domain
   models, tenancy, auth, and agents. A package that insists on its own
   `Document` model fights every app it lands in.

2. **Package owns its tables, with a polymorphic owner.** `rag_documents` and
   `rag_chunks` ship with the package and point at any host model through
   `owner_type` / `owner_id`. This keeps host domain models intact while the
   package retains control of the index-tuning surface it exists to provide.

3. **Structure-aware chunks with provenance.** Chunks carry `page_no`,
   `heading_path`, and `content_type`. Citations are table stakes for a
   document-analysis product, and metadata filtering (`content_type = 'table'`)
   depends on the same data.

4. **The package does not manage containers.** Config takes `docling.url` and
   `gotenberg.url`. The package ships an example Compose block and a
   `rag:health` command. Spawning Docker from PHP is a footgun in every host
   environment.

5. **Retrieval ships as a search API plus a drop-in tool.** The package owns
   `Rag::search()` and a `SearchDocuments` tool class, including the tool
   description — retrieval quality lives in that prompt. The agent stays
   host-side, where credits, tracing, and tenancy live.

6. **Embedding dimension is fixed per install.** Config sets the dimension;
   every chunk row stamps its `embedding_model`. Changing models is a
   deliberate `rag:reembed`, never a live mix.

## Findings that shaped the design

**Docling ingests Office formats natively.** Its `from_formats` accepts
`docx`, `pptx`, `xlsx`, `html`, `md`, `asciidoc`, `image`, and `pdf`. Routing a
DOCX through LibreOffice to PDF and re-parsing its layout discards structure
Docling would have read directly from the OOXML. Gotenberg is therefore a
**fallback converter**, not a mandatory pre-processing stage.

**Docling-serve chunks server-side.** It exposes `HybridChunkerOptions` (token
limits, custom tokenizer, merging undersized chunks that share headings) and
`HierarchicalChunkerOptions`. There is no PHP chunker to write. The package
sends options and maps what returns.

**`gotenberg/gotenberg-php` is an official client.** No hand-rolled multipart.

**`laravel/ai` `Stores` is provider-hosted vector storage**, not pgvector. The
package owns its own storage layer. `Embeddings` and `Reranking` are reusable
as-is, across OpenAI, OpenRouter, Jina, Voyage, and Cohere.

## Architecture

### Ingestion pipeline

```
host: Rag::ingest($file, owner: $dataSource)
  │
  ├─ FormatRouter ─ native docling format? ──yes──┐
  │                       │no                     │
  │                 GotenbergConverter            │
  │                 (→ PDF, fallback only)        │
  │                       └────────────────────────┤
  │                                                ▼
  ├─ DoclingClient: POST /v1/convert/source/async ──► task_id
  │                 poll /status/poll/{task_id} until success|failure
  │                 to_formats: ["json"], hybrid chunker options
  │                                                ▼
  ├─ ChunkMapper: docling chunks ──► rag_chunks rows
  │                 (text, heading_path, page_no, content_type, ord)
  │                                                ▼
  ├─ Embedder: Ai::embeddings() batched ──► vector(N) + model stamp
  │                                                ▼
  └─ tsvector generated column + HNSW index → status: ready
```

Each stage is a class behind an interface, independently testable against a
faked HTTP layer. Polling is a re-dispatched job with exponential backoff. No
worker ever blocks in `sleep()`.

### Gotenberg toggle

`php artisan rag:install --with-gotenberg` publishes the config and the example
Compose service. `docling-rag.gotenberg.enabled` is the live switch, so an
environment can disable it without reinstalling. Disabled plus an unsupported format raises
`UnsupportedFormatException` — never a silent skip.

## Data model

### `rag_documents`

One row per ingested file.

| Column | Notes |
|---|---|
| `owner_type`, `owner_id` | Morph to any host model |
| `disk`, `path`, `mime` | Source location |
| `sha256` | Unique with owner — re-upload is idempotent |
| `status` | pending, converting, chunking, embedding, ready, failed |
| `converted_via` | `native` or `gotenberg` |
| `docling_task_id` | For poll resumption |
| `page_count` | |
| `failure_reason` | Human-readable, surfaced to the host |

### `rag_chunks`

| Column | Notes |
|---|---|
| `rag_document_id` | FK, cascades on delete |
| `ord` | Position within the document |
| `parent_id` | Nullable, reserved for parent-child retrieval |
| `text` | |
| `heading_path` | jsonb array |
| `page_no`, `content_type`, `token_count` | Provenance and filtering |
| `embedding` | `vector(N)` or `halfvec(N)` |
| `embedding_model` | Drift guard |
| `embedded_at` | Nullable — enables resumable embedding |
| `tsv` | Generated stored `tsvector` |

Indexes: HNSW on `embedding` (cosine), GIN on `tsv`, btree on
`(rag_document_id, ord)`, btree on the owner morph.

`vector` and `tsvector` are written via `DB::statement`. Laravel's schema
builder has no type for either.

### Constraints to design around

**pgvector HNSW caps at 2000 dimensions.** `text-embedding-3-large` is 3072 and
cannot take an HNSW index on a plain `vector` column. Options: stay on
`text-embedding-3-small` (1536), request reduced `dimensions` from OpenAI, or
use `halfvec` (4096 cap, half the storage, negligible recall loss). Default is
`vector(1536)`; config exposes the dimension and a `halfvec` toggle, and the
migration branches on it.

**Filtered ANN degrades.** Every search is owner-scoped, which post-filters
HNSW results — a narrow filter over a large corpus silently returns fewer than
`k` rows. The search layer sets `hnsw.iterative_scan = relaxed_order`
per-connection. The install check pins pgvector >= 0.8.

**Index builds are expensive.** HNSW over millions of chunks takes minutes to
hours and wants `maintenance_work_mem` raised. Index creation ships as
`rag:index`, separate from the migration, so large installs choose the moment.

## Retrieval

`Rag::search($query, owner: $ds, filters: [...], k: 8)` returns `ChunkResult[]`
carrying text, `heading_path`, `page_no`, score, and a document reference.

Four swappable stages:

1. **Embed the query** with the corpus model. Search throws when
   `docling-rag.embedding.model` does not match the stored `embedding_model`.
   Cached by query hash with a short TTL.

2. **Two retrievers, one round-trip.** A single SQL statement with two CTEs: a
   vector CTE ordered by `embedding <=> :q` limited to 50, and an FTS CTE
   ordered by `ts_rank_cd(tsv, websearch_to_tsquery(...))` limited to 50. Both
   take the same owner and metadata filters. Fusing in Postgres beats two
   round-trips fused in PHP.

3. **RRF fusion** — `sum(1 / (60 + rank))` across both lists. Rank-based, so
   cosine distance and `ts_rank` never need normalizing against each other.
   This is what makes hybrid search work rather than merely run.

4. **Rerank (optional)** — the top ~30 fused results through `Ai::rerank()`
   (Jina, Cohere, or Voyage, already in `laravel/ai`). Off by default because
   it costs a call; it is the largest quality gain on real documents.

**Per-document capping** applies before return. Without it, one verbose PDF
takes every slot and the agent sees a single source.

**FTS language** is fixed at the column via `to_tsvector('english', ...)`.
Config exposes the regconfig. Multi-language corpora need a per-row config
column — out of scope for v1.

### `SearchDocuments` tool

Wraps `Rag::search()`. The package owns its description: when to search, how to
phrase queries as standalone questions, that it should re-search with different
phrasing rather than give up, and that answers must cite `page_no`. Hosts
register it on their own agents alongside their own middleware.

## Failure modes

- **Docling task dies or hangs.** Poll job has max attempts and exponential
  backoff. A terminal `failure` marks the document `failed` with its reason.
- **Partial embedding.** The embed job only picks up chunks with a null
  `embedded_at`, so a provider 429 mid-batch resumes rather than restarting a
  4,000-chunk document.
- **Oversized documents.** Config caps pages and chunk count. Over the cap
  fails fast with a clear reason instead of burning a large embedding bill.
- **Gotenberg ZIP responses.** Multiple input files return a ZIP. v1 asserts
  the single-file path.
- **Model drift.** Search refuses to run on a dimension or model mismatch.
  `rag:reembed` is the migration path.
- **Document deletion** cascades to chunks through the shipped FK.

## Testing

- Every stage is unit-tested against `Http::fake()` with recorded Docling and
  Gotenberg fixtures. No containers in the fast suite.
- `Rag::fake()` lets host apps test their agents without a vector database.
- Search correctness — RRF ordering, filters, per-document capping — requires
  real Postgres with pgvector. SQLite cannot fake `<=>`. These run as a tagged
  group against a CI service container.
- One opt-in live-container integration test guards the real Docling contract.
  Fixtures rot; this is what catches it.

## Packaging

Package: **`blue-hex/laravel-docling-rag`**, namespace `BlueHex\DoclingRag\`,
config file `docling-rag.php`.

A separate repository: `orchestra/testbench` plus Pest, an auto-discovered
service provider, a `Rag` facade, published config, and package-loaded
migrations. Hosts wire in through a `HasRagDocuments` trait and one config file.

The package emits `DocumentIngested` and `IngestionFailed`. That is how this
application attaches credits, `ai_traces`, and notifications without the
package knowing any of them exist.

Commands: `rag:install [--with-gotenberg]`, `rag:index`, `rag:health`,
`rag:reembed`.

## Host requirements

- PostgreSQL with pgvector >= 0.8. This application's Compose service moves
  from `postgres:16` to `pgvector/pgvector:pg16`.
- The package is Postgres-only. `.env.example` currently defaults to SQLite;
  installs must set `DB_CONNECTION=pgsql`.
- A reachable docling-serve instance, and gotenberg only when enabled.
- An embedding provider configured in `laravel/ai`.
