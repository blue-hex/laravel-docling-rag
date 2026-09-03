# Document RAG for Laravel via Docling-serve, with Gotenberg as a conversion fallback

[![Latest Version on Packagist](https://img.shields.io/packagist/v/blue-hex/laravel-docling-rag.svg?style=flat-square)](https://packagist.org/packages/blue-hex/laravel-docling-rag)
[![GitHub Tests Action Status](https://github.com/blue-hex/laravel-docling-rag/actions/workflows/run-tests.yml/badge.svg)](https://github.com/blue-hex/laravel-docling-rag/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://github.com/blue-hex/laravel-docling-rag/actions/workflows/fix-php-code-style-issues.yml/badge.svg)](https://github.com/blue-hex/laravel-docling-rag/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/blue-hex/laravel-docling-rag.svg?style=flat-square)](https://packagist.org/packages/blue-hex/laravel-docling-rag)

Turns uploaded documents into cited, embeddable chunks backed by Postgres/pgvector, and exposes hybrid search to any `laravel/ai` agent. The package owns conversion, chunking, embedding, storage, and retrieval. The host app keeps its domain models, tenancy, auth, and agents.

## Requirements

- PHP 8.4+
- Laravel 12 or 13
- PostgreSQL with [pgvector](https://github.com/pgvector/pgvector) >= 0.8
- A reachable [docling-serve](https://github.com/docling-project/docling-serve) instance
- An embedding provider configured in [laravel/ai](https://github.com/laravel/ai)
- [Gotenberg](https://gotenberg.dev) only when the conversion fallback is enabled

This package does not manage containers. Point config at URLs you already run. If you need help in getting started with Docker Compose, refer to the sample configuration below.

## Sample Docker Compose

```yaml
services:
  postgres:
    image: pgvector/pgvector:pg16
    container_name: laravel-docling-rag-postgres
    restart: unless-stopped
    environment:
      POSTGRES_USER: postgres
      POSTGRES_PASSWORD: postgres
      POSTGRES_DB: laravel_docling_rag
    ports:
      # Host port is overridable to avoid clashing with an existing Postgres.
      # Default is 5432; set POSTGRES_PORT to remap (e.g. POSTGRES_PORT=5433 docker compose up -d).
      - '${DB_PORT:-5432}:5432'
    volumes:
      - laravel_docling_rag_pgdata:/var/lib/postgresql/data
    healthcheck:
      test: ['CMD-SHELL', 'pg_isready -U postgres']
      interval: 5s
      timeout: 5s
      retries: 5

  docling-serve:
    image: ghcr.io/docling-project/docling-serve:latest
    container_name: laravel-docling-rag-docling-serve
    restart: unless-stopped
    ports:
      - '${DOCLING_PORT:-5001}:5001'
    environment:
      DOCLING_SERVE_ENABLE_UI: 'false'

  gotenberg:
    image: gotenberg/gotenberg:latest
    container_name: laravel-docling-rag-gotenberg
    restart: unless-stopped
    ports:
      - "3000:3000"
    profiles:
      - gotenberg

volumes:
  laravel_docling_rag_pgdata:
    driver: local
```

## Installation

```bash
composer require blue-hex/laravel-docling-rag
php artisan rag:install
php artisan migrate
```

Enable the Gotenberg fallback and publish the example Compose file:

```bash
php artisan rag:install --with-gotenberg
```

After embeddings land, build the search indexes (HNSW + GIN) at a time you choose — see [Search indexes](#search-indexes) for why the timing matters:

```bash
php artisan rag:index
```

Check Docling, Gotenberg (if enabled), and pgvector:

```bash
php artisan rag:health
```

## Configuration

Publish `config/docling-rag.php` and set:

- `DOCLING_URL` — docling-serve base URL (default `http://localhost:5001`)
- `DOCLING_API_KEY` — sent as `X-Api-Key` when the server requires it
- `DOCLING_TIMEOUT` — timeout in seconds for poll/result requests (default `120`)
- `DOCLING_UPLOAD_TIMEOUT` — separate, longer timeout for the upload itself (default `300`) — a large file takes longer to transfer than a status poll does
- `DOCLING_RAG_MAX_UPLOAD_MB` — reject a source file before it's read into memory or sent to Docling (default `100`, `0` disables)
- `DOCLING_RAG_GOTENBERG_ENABLED` / `GOTENBERG_URL` — fallback converter
- `DOCLING_RAG_EMBEDDING_MODEL` / `DOCLING_RAG_EMBEDDING_DIMENSIONS` — default `text-embedding-3-small` / `1536`
- `DOCLING_RAG_EMBEDDING_HALFVEC` — use `halfvec` when you need more than 2000 dimensions
- `DOCLING_RAG_MAX_PAGES` / `DOCLING_RAG_MAX_CHUNKS` — fail fast instead of burning an embedding bill
- `DOCLING_DO_OCR` / `DOCLING_CHUNK_MAX_TOKENS` / `DOCLING_CHUNK_TOKENIZER` — defaults for `docling.request_options` (see [Docling request options](#docling-request-options))
- `DOCLING_RAG_RETRIEVAL_K` — chunks returned per search (default `8`)
- `DOCLING_RAG_RETRIEVAL_PER_DOCUMENT_CAP` — max chunks from one document (default `3`, `0` disables)
- `DOCLING_RAG_RERANK_ENABLED` / `DOCLING_RAG_RERANK_PROVIDER` / `DOCLING_RAG_RERANK_MODEL` — optional cross-encoder rerank of the top candidates
- `DOCLING_RAG_RETRIEVAL_CANDIDATES` / `DOCLING_RAG_RETRIEVAL_RRF_K` — per-retriever depth and RRF constant

An example Compose file ships as `docker-compose.example.yml` (`docling-serve`, plus `gotenberg` under the `gotenberg` profile).

## Usage

Add the trait to the host model that owns documents:

```php
use BlueHex\DoclingRag\Support\HasRagDocuments;
use Illuminate\Database\Eloquent\Model;

class DataSource extends Model
{
    use HasRagDocuments;
}
```

Ingest a file. Re-uploads of the same bytes for the same owner are idempotent:

```php
use BlueHex\DoclingRag\Facades\Rag;

$document = Rag::ingest($request->file('document'), owner: $dataSource);
// or
$document = $dataSource->ingestDocument($request->file('document'));
```

Native Docling formats (PDF, Office, HTML, Markdown, images, …) go straight to docling-serve hybrid chunking, streamed from disk rather than buffered into memory. Anything else goes through Gotenberg → PDF when enabled; otherwise ingestion raises `UnsupportedFormatException`. A `.markdown` extension is resubmitted to Docling as `.md` automatically — Docling only recognises `.md`, so this is transparent to callers.

### Docling request options

`config('docling-rag.docling.request_options')` sets the default fields sent to Docling on every ingest — OCR, table mode, page range, tokenizer, anything from [docling-serve's `/v1/chunk/hybrid/file/async` schema](https://github.com/docling-project/docling-serve), keyed by its exact field name (`convert_*`, `chunking_*`):

```php
// config/docling-rag.php
'docling' => [
    'request_options' => [
        'convert_do_ocr' => true,
        'chunking_max_tokens' => 512,
        'chunking_merge_peers' => true,
        'chunking_use_markdown_tables' => true,
    ],
],
```

Override per ingest — merged over the config default, per-call wins:

```php
// e.g. skip OCR for a document you know is already text, or force it for a scan
$dataSource->ingestDocument($file, options: ['convert_do_ocr' => false]);

Rag::ingest($file, owner: $dataSource, options: [
    'convert_table_mode' => 'fast',
    'convert_page_range' => [1, 10],
]);
```

Options are not persisted — a manual re-ingest of a previously failed document (`Rag::ingest()` called again with the same bytes) needs `options` passed again if you want anything other than the config default.

### Ownership and scoping

Every document is stored against a polymorphic **owner**, and both ingestion and search are scoped to a single owner — retrieval's `WHERE` is exactly `owner_type = ? AND owner_id = ?`. The package has no concept of a user; it trusts the owner you pass. That has two consequences worth internalising:

- **Choose the owner to match your search boundary.** Whatever model you put `HasRagDocuments` on becomes the unit of "search within this." Put it on `User` and one `Rag::search()` covers that user's entire corpus; put it on a `Project` or `Document` for a narrower corpus. A single search targets one owner — it cannot union documents across many owners, so if you need the whole-user view, the user must be the owner. To narrow *within* an owner, use `filters: ['document_ids' => [...]]`.

- **Authorization is yours, not the package's.** The package runs no ownership checks — hand it an owner and it returns that owner's chunks. Always derive the owner from the authenticated user rather than from a request id, so a user can only ever reach their own:

  ```php
  public function search(Request $request, Project $project)
  {
      abort_if($project->user_id !== $request->user()->id, 404);

      return Rag::search($request->string('q'), owner: $project);
  }
  ```

### Tracking ingestion

Ingestion runs on the queue and moves a `RagDocument` through a status lifecycle:

```
pending → converting → chunking → embedding → ready
                                             ↘ failed   (from any stage)
```

The package fires two events, both carrying the `RagDocument`. They mark the **terminal** states — the document has finished, one way or the other:

- `DocumentIngested` — the document reached `ready`; its chunks are embedded and searchable.
- `IngestionFailed` — the document reached `failed`; read `$document->failure_reason` for why. It fires once, not on every retry. When Docling itself rejected or skipped the document, `failure_reason` carries Docling's own message (e.g. an unrecognised format) rather than a generic "no chunks" fallback.

Both are dispatched from queued jobs, so your listeners run in the worker — the right place for credits, tracing, notifications, or broadcasting to the UI:

```php
use BlueHex\DoclingRag\Events\DocumentIngested;
use BlueHex\DoclingRag\Events\IngestionFailed;
use Illuminate\Support\Facades\Event;

Event::listen(function (DocumentIngested $event): void {
    $document = $event->document;                 // status === DocumentStatus::Ready
    $document->owner->notify(new DocumentReady($document));
});

Event::listen(function (IngestionFailed $event): void {
    report(new RuntimeException($event->document->failure_reason));
});
```

Prefer a dedicated listener class for anything non-trivial:

```php
// app/Listeners/BroadcastIngestionStatus.php
public function handle(DocumentIngested $event): void
{
    IngestionStatusChanged::dispatch($event->document->id, 'ready');
}
```

Register it in your `EventServiceProvider` (or via auto-discovery) as you would any Laravel event.

For a **live progress bar** — the interim `converting`/`chunking`/`embedding` steps — there is no per-step event; read the column instead. Poll `RagDocument::find($id)->status` (a `DocumentStatus` enum) from your frontend, or broadcast each change yourself from the terminal listeners above. `$document->status->isInFlight()` is true for every non-terminal state.

Change embedding models with a deliberate re-embed, never a live mix:

```bash
php artisan rag:reembed
php artisan rag:reembed 42
```

## Retrieval

`Rag::search()` runs hybrid search — vector and full-text retrievers fused with Reciprocal Rank Fusion in a single Postgres round-trip — scoped to one owner. It returns `ChunkResult`s carrying the text, `page_no`, `heading_path`, `content_type`, and a fused `score`.

```php
use BlueHex\DoclingRag\Facades\Rag;

$results = Rag::search('What is the refund window?', owner: $dataSource);

foreach ($results as $chunk) {
    $chunk->text;        // the passage
    $chunk->pageNo;      // cite this
    $chunk->headingPath; // e.g. ['Policies', 'Refunds']
    $chunk->score;
}
```

Narrow with metadata filters, cap the results, and enable rerank per call via config:

```php
use BlueHex\DoclingRag\Enums\ContentType;

$tables = Rag::search('quarterly revenue', $dataSource, filters: [
    'content_type' => ContentType::Table,
], k: 5);
```

Search refuses to run when the configured `embedding.model` no longer matches the stored vectors (`ModelMismatchException`) — migrate with `rag:reembed`.

### The `SearchDocuments` tool

Register the drop-in tool on your own agent. The package owns the tool description — how to phrase standalone queries, when to re-search, and that answers must cite `page_no`. The host binds the owner to scope it and keeps its own credits, tracing, and tenancy:

```php
use BlueHex\DoclingRag\Tools\SearchDocuments;

$agent->tools([
    SearchDocuments::for($dataSource),
    // optional: scope and cap it
    // SearchDocuments::for($dataSource, filters: ['content_type' => ContentType::Table], k: 5),
]);
```

Test host agents without a vector database — `Rag::fake()` swaps search for canned results and records every call:

```php
use BlueHex\DoclingRag\Retrieval\ChunkResult;

$fake = Rag::fake([
    new ChunkResult(1, $doc->id, 'Refunds within 30 days.', pageNo: 4, headingPath: ['Refunds'], contentType: null, score: 0.9),
]);

// ... exercise your agent ...

expect($fake->searches)->toHaveCount(1);
```

### Search indexes

`Rag::search()` works with no indexes at all — Postgres falls back to a sequential scan, which is fine for a few hundred chunks. At real volume, `rag:index` is what keeps retrieval fast:

```bash
php artisan rag:index
```

It creates the two indexes the hybrid query relies on, one per retriever:

- an **HNSW** index on the `embedding` column (approximate nearest-neighbour, cosine) — turns vector search from a full-table distance scan into a graph walk;
- a **GIN** index on the `tsv` column — the inverted index that makes the full-text half fast.

Run it **after embeddings land**. Embedding happens asynchronously on the queue, so `embedding` is null until those jobs finish — there is nothing to index before then, and an index in place only adds write cost to every insert.

Run it **at a time you choose**. Building an HNSW index reads every vector and is CPU- and memory-heavy on a large table, so it is a deliberate command rather than a migration or a per-upload hook: run it once after a bulk ingest, off-peak, and rebuild after an `rag:reembed` that changes the model or dimensions. It uses `CREATE INDEX IF NOT EXISTS`, so re-running it is safe and idempotent.

## Testing

```bash
composer test
```

The fast suite uses SQLite, `Http::fake()`, and `Embeddings::fake()`. Search correctness (RRF ordering, owner scoping, filters) needs real pgvector — those tests are tagged `integration` and skip unless `RAG_PG_HOST` (and the other `RAG_PG_*` vars) point at a Postgres + pgvector database. Set `DOCLING_LIVE_URL` to run the opt-in live Docling contract test.

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Credits

- [Rohan Krishna](https://github.com/rohankrishna)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
