# Document RAG for Laravel via Docling-serve, with Gotenberg as a conversion fallback

[![Latest Version on Packagist](https://img.shields.io/packagist/v/blue-hex/laravel-docling-rag.svg?style=flat-square)](https://packagist.org/packages/blue-hex/laravel-docling-rag)
[![GitHub Tests Action Status](https://github.com/blue-hex/laravel-docling-rag/actions/workflows/run-tests.yml/badge.svg)](https://github.com/blue-hex/laravel-docling-rag/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://github.com/blue-hex/laravel-docling-rag/actions/workflows/fix-php-code-style-issues.yml/badge.svg)](https://github.com/blue-hex/laravel-docling-rag/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/blue-hex/laravel-docling-rag.svg?style=flat-square)](https://packagist.org/packages/blue-hex/laravel-docling-rag)

Turns uploaded documents into cited, embeddable chunks backed by Postgres/pgvector. The package owns conversion, chunking, embedding, and storage. The host app keeps its domain models, tenancy, auth, and agents.

Retrieval (hybrid search and the `SearchDocuments` tool) is not in this release.

## Requirements

- PHP 8.4+
- Laravel 12 or 13
- PostgreSQL with [pgvector](https://github.com/pgvector/pgvector) >= 0.8
- A reachable [docling-serve](https://github.com/docling-project/docling-serve) instance
- An embedding provider configured in [laravel/ai](https://github.com/laravel/ai)
- [Gotenberg](https://gotenberg.dev) only when the conversion fallback is enabled

This package does not manage containers. Point config at URLs you already run.

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

## Testing

```bash
composer test
```

The fast suite uses SQLite, `Http::fake()`, and `Embeddings::fake()`. Set `DOCLING_LIVE_URL` to run the opt-in live Docling contract test.

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Credits

- [Rohan Krishna](https://github.com/rohankrishna)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
