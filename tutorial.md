# Give a Laravel agent documents it can cite

The way to fail at document RAG is to own the pipeline. Conversion, chunking, embedding, storage, retrieval: each piece looks small. You spend a year on them. You still leak another tenant's chunks, because search was not scoped to the right owner. Or you change the embedding model in `.env` and answers go sideways, because the old vectors and the new query live in different spaces.

I wrote `blue-hex/laravel-docling-rag` so I would not have to own those five jobs again. The package sends the file to [Docling-serve](https://github.com/docling-project/docling-serve), chunks it there, embeds with [laravel/ai](https://github.com/laravel/ai), writes vectors into Postgres/pgvector, and returns passages with a page number. You keep models, policies, tenancy, and the agent.

When you finish this walkthrough you can upload a PDF onto a `Project`, ask a question, and get a cited passage.

## What you need

PHP 8.4, Laravel 12 or 13, PostgreSQL with [pgvector](https://github.com/pgvector/pgvector) 0.8 or newer, a reachable Docling-serve instance, an embedding provider already configured in laravel/ai, and a queue worker. The package does not start containers for you. Point config at URLs you already run.

If you want a sample Compose file, `php artisan rag:install --with-gotenberg` publishes one (`docling-serve`, plus Gotenberg under a profile). That flag also turns on the conversion fallback for formats Docling will not take. The rest of the knobs live in the [README](https://github.com/blue-hex/laravel-docling-rag).

## Install it

```bash
composer require blue-hex/laravel-docling-rag
php artisan rag:install
php artisan migrate
php artisan rag:health
```

`rag:install` publishes `config/docling-rag.php` and the migrations. `rag:health` checks Docling, Gotenberg if you enabled it, and pgvector. Set `DOCLING_URL` if Docling is not at `http://localhost:5001`. Set `DOCLING_RAG_EMBEDDING_MODEL` and `DOCLING_RAG_EMBEDDING_DIMENSIONS` to match the model laravel/ai will call. The defaults are `text-embedding-3-small` and 1536. Those two values are a contract with every row you store. Treat a later change as a migration.

Leave `rag:index` for later. There is nothing useful to index until embeddings exist.

Keep a worker running. Ingestion is queued.

```bash
php artisan queue:work
```

## Pick the owner

Make this decision before you write a controller.

Every document hangs off a polymorphic owner. Search is one `WHERE`: `owner_type` and `owner_id`. The package has no User model and no policy. Hand it an owner and it returns that owner's chunks. If you load a `Project` from a request id and skip the auth check, you return another user's documents.

Put `HasRagDocuments` on the model that is the search boundary. Put it on `User` and one search covers that user's whole corpus. Put it on `Project` and search stays inside one project. A call cannot union owners. If you later want everything this user uploaded, the user has to be the owner. To narrow inside one owner, pass `filters: ['document_ids' => [...]]`.

I put it on `Project`. That matches how I search.

```php
use BlueHex\DoclingRag\Support\HasRagDocuments;
use Illuminate\Database\Eloquent\Model;

class Project extends Model
{
    use HasRagDocuments;
}
```

Derive that `Project` from the authenticated user. Do not take the owner id from the request body and look it up raw.

```php
use BlueHex\DoclingRag\Facades\Rag;

public function search(Request $request, Project $project)
{
    abort_if($project->user_id !== $request->user()->id, 404);

    return Rag::search($request->string('q'), owner: $project);
}
```

The scoped query is cheap. The cost shows up if you pass an owner the current user is not allowed to see.

## Ingest a file

```php
use BlueHex\DoclingRag\Facades\Rag;

$document = $project->ingestDocument($request->file('document'));
// same thing:
$document = Rag::ingest($request->file('document'), owner: $project);
```

Same bytes, same owner, same row. Re-uploads are idempotent. A previously failed document gets queued again.

The file goes to disk, a `RagDocument` starts at `pending`, and `IngestDocumentJob` takes over. Status then moves:

```
pending → converting → chunking → embedding → ready
```

Any stage can land on `failed`. Read `$document->failure_reason` when it does. Native Docling formats (PDF, Office, HTML, Markdown, images) go straight to Docling. Anything else needs Gotenberg, or you get `UnsupportedFormatException`.

Search an in-flight document and you get an empty set. Wait for `ready`. There is no per-step event. Poll `$document->status` if you want a progress bar. `$document->status->isInFlight()` is true until the document is `ready` or `failed`.

```php
use BlueHex\DoclingRag\Enums\DocumentStatus;

$document->refresh();

if ($document->status === DocumentStatus::Ready) {
    // search
}
```

The two events fire at the end, from the worker, once:

```php
use BlueHex\DoclingRag\Events\DocumentIngested;
use BlueHex\DoclingRag\Events\IngestionFailed;

Event::listen(function (DocumentIngested $event): void {
    $event->document->owner->notify(new DocumentReady($event->document));
});

Event::listen(function (IngestionFailed $event): void {
    report(new RuntimeException($event->document->failure_reason));
});
```

That is the place for credits, tracing, and a toast. Use a listener class if the work is more than a few lines. `DocumentIngested` is also a decent place to tell the UI the file is searchable.

Config sets the default Docling fields (`convert_do_ocr`, chunk size, and so on). Override per call when you know the file:

```php
$project->ingestDocument($file, options: ['convert_do_ocr' => false]);
```

Options are not stored. A retry of a failed document needs them passed again, or you get the config default.

## Search, then hang it on an agent

`Rag::search()` runs hybrid search (vector plus full-text, fused with Reciprocal Rank Fusion) in one Postgres round-trip, scoped to that owner. Keyword hits and semantic hits both count. You get `ChunkResult` values: the passage, a page number, a heading path, a fused score.

```php
use BlueHex\DoclingRag\Facades\Rag;

$results = Rag::search('What is the refund window?', owner: $project);

foreach ($results as $chunk) {
    $chunk->text;
    $chunk->pageNo;
    $chunk->headingPath; // ['Policies', 'Refunds']
    $chunk->score;
}
```

Cap how many chunks come back with `k`. Filter by `content_type` when you only want tables, or by `document_ids` when the question is about one file.

```php
use BlueHex\DoclingRag\Enums\ContentType;

$tables = Rag::search('quarterly revenue', $project, filters: [
    'content_type' => ContentType::Table,
], k: 5);
```

If you already have a laravel/ai agent, register the drop-in tool. You bind the owner. The package owns the tool description: phrase a standalone question, cite the page, re-search before declaring the documents empty.

```php
use BlueHex\DoclingRag\Tools\SearchDocuments;

$agent->tools([
    SearchDocuments::for($project),
]);
```

Test the agent without a vector database. `Rag::fake()` swaps search for canned results and records the calls.

```php
use BlueHex\DoclingRag\Retrieval\ChunkResult;

$fake = Rag::fake([
    new ChunkResult(
        1,
        $document->id,
        'Refunds within 30 days.',
        pageNo: 4,
        headingPath: ['Refunds'],
        contentType: null,
        score: 0.9,
    ),
]);

// exercise the agent

expect($fake->searches)->toHaveCount(1);
```

## Three ways to lose

Authorize the owner yourself. The package will not. The controller sketch above is the whole pattern: abort, then search.

Do not mix embedding models in one table. Search throws `ModelMismatchException` if the configured model no longer matches the stored vectors. Change models on purpose:

```bash
php artisan rag:reembed
php artisan rag:index
```

`rag:reembed 42` does one document.

Do not build the search indexes until embeddings exist. `Rag::search()` works with no indexes. Postgres sequential-scans, which is fine for a few hundred chunks. `php artisan rag:index` creates the HNSW index on `embedding` and the GIN index on `tsv`. Building HNSW reads every vector and costs CPU and memory, so it is a command you run after a bulk ingest, off-peak, and again after a re-embed that changes dimensions. It is `CREATE INDEX IF NOT EXISTS`. Running it twice is safe. Running it on a table of null embeddings is a waste.

Gotenberg, rerank, page caps, and Docling request fields are in the README. Skip them until ingest and search work.

The package is [blue-hex/laravel-docling-rag](https://packagist.org/packages/blue-hex/laravel-docling-rag). Source and the remaining knobs: [github.com/blue-hex/laravel-docling-rag](https://github.com/blue-hex/laravel-docling-rag).
