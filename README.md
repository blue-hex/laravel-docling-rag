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

After embeddings land, build the search indexes (HNSW + GIN) at a time you choose:

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
- `DOCLING_RAG_GOTENBERG_ENABLED` / `GOTENBERG_URL` — fallback converter
- `DOCLING_RAG_EMBEDDING_MODEL` / `DOCLING_RAG_EMBEDDING_DIMENSIONS` — default `text-embedding-3-small` / `1536`
- `DOCLING_RAG_EMBEDDING_HALFVEC` — use `halfvec` when you need more than 2000 dimensions
- `DOCLING_RAG_MAX_PAGES` / `DOCLING_RAG_MAX_CHUNKS` — fail fast instead of burning an embedding bill
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

Native Docling formats (PDF, Office, HTML, Markdown, images, …) go straight to docling-serve hybrid chunking. Anything else goes through Gotenberg → PDF when enabled; otherwise ingestion raises `UnsupportedFormatException`.

Listen for host-side side effects (credits, traces, notifications):

```php
use BlueHex\DoclingRag\Events\DocumentIngested;
use BlueHex\DoclingRag\Events\IngestionFailed;
```

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
