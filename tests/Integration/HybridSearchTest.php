<?php

use BlueHex\DoclingRag\Contracts\EmbedsQueries;
use BlueHex\DoclingRag\Facades\Rag;
use BlueHex\DoclingRag\Models\RagChunk;
use BlueHex\DoclingRag\Models\RagDocument;
use BlueHex\DoclingRag\Tests\Fixtures\DataSource;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * Search correctness — RRF ordering, owner scoping, metadata filtering — needs
 * a real pgvector database. SQLite cannot fake `<=>`. Point RAG_PG_* at a
 * Postgres + pgvector >= 0.8 service to run this group.
 */
function usePgvector(): void
{
    config([
        'docling-rag.embedding.dimensions' => 4,
        'docling-rag.embedding.halfvec' => false,
        'docling-rag.retrieval.per_document_cap' => 0,
        'docling-rag.retrieval.rerank.enabled' => false,
        'database.connections.pgvector' => [
            'driver' => 'pgsql',
            'host' => getenv('RAG_PG_HOST') ?: '127.0.0.1',
            'port' => getenv('RAG_PG_PORT') ?: '5432',
            'database' => getenv('RAG_PG_DB') ?: 'testing',
            'username' => getenv('RAG_PG_USER') ?: 'postgres',
            'password' => getenv('RAG_PG_PASSWORD') ?: 'postgres',
        ],
    ]);

    config(['database.default' => 'pgvector']);

    DB::statement('CREATE EXTENSION IF NOT EXISTS vector');

    foreach (['rag_chunks', 'rag_documents', 'data_sources'] as $table) {
        Schema::dropIfExists($table);
    }

    Schema::create('data_sources', function ($table): void {
        $table->id();
        $table->string('name')->nullable();
        $table->timestamps();
    });

    foreach (File::allFiles(__DIR__.'/../../database/migrations') as $migration) {
        (include $migration->getRealPath())->up();
    }

    app(Kernel::class)->call('rag:index');
}

/**
 * @param  list<float>  $embedding
 */
function seedChunk(RagDocument $document, int $ord, string $text, array $embedding, string $contentType = 'text'): void
{
    $chunk = RagChunk::factory()->create([
        'rag_document_id' => $document->id,
        'ord' => $ord,
        'text' => $text,
        'content_type' => $contentType,
        'embedding_model' => config('docling-rag.embedding.model'),
        'embedded_at' => now(),
    ]);

    DB::statement('UPDATE rag_chunks SET embedding = CAST(? AS vector) WHERE id = ?', [
        json_encode($embedding), $chunk->id,
    ]);
}

function fakeQueryVector(array $vector): void
{
    app()->instance(EmbedsQueries::class, new class($vector) implements EmbedsQueries
    {
        public function __construct(protected array $vector) {}

        public function embed(string $query): array
        {
            return $this->vector;
        }
    });
}

it('fuses vector and full-text results scoped to the owner', function () {
    usePgvector();

    $owner = DataSource::create(['name' => 'mine']);
    $other = DataSource::create(['name' => 'theirs']);

    $doc = RagDocument::factory()->create([
        'owner_type' => $owner->getMorphClass(),
        'owner_id' => $owner->getKey(),
    ]);
    $otherDoc = RagDocument::factory()->create([
        'owner_type' => $other->getMorphClass(),
        'owner_id' => $other->getKey(),
    ]);

    // Near the query vector AND lexically matching -> should rank first.
    seedChunk($doc, 0, 'the zebra crossing was repainted', [1.0, 0.0, 0.0, 0.0]);
    // Lexically matching only.
    seedChunk($doc, 1, 'a zebra escaped the enclosure', [0.0, 0.0, 1.0, 0.0]);
    // Vector-near only.
    seedChunk($doc, 2, 'unrelated maintenance notes', [0.9, 0.1, 0.0, 0.0]);
    // Other owner, matches strongly but must be excluded.
    seedChunk($otherDoc, 0, 'zebra zebra zebra', [1.0, 0.0, 0.0, 0.0]);

    fakeQueryVector([1.0, 0.0, 0.0, 0.0]);

    $results = Rag::search('zebra', $owner);

    expect($results->first()->text)->toBe('the zebra crossing was repainted')
        ->and($results->every(fn ($r) => $r->documentId === $doc->id))->toBeTrue()
        ->and($results->pluck('text'))->not->toContain('zebra zebra zebra');
})->group('integration')->skip(
    fn () => blank(getenv('RAG_PG_HOST')),
    'Set RAG_PG_HOST (and RAG_PG_*) to run the pgvector search tests.',
);

it('filters by content_type', function () {
    usePgvector();

    $owner = DataSource::create(['name' => 'mine']);
    $doc = RagDocument::factory()->create([
        'owner_type' => $owner->getMorphClass(),
        'owner_id' => $owner->getKey(),
    ]);

    seedChunk($doc, 0, 'quarterly revenue prose', [1.0, 0.0, 0.0, 0.0], 'text');
    seedChunk($doc, 1, 'quarterly revenue table', [1.0, 0.0, 0.0, 0.0], 'table');

    fakeQueryVector([1.0, 0.0, 0.0, 0.0]);

    $results = Rag::search('quarterly revenue', $owner, filters: ['content_type' => 'table']);

    expect($results)->toHaveCount(1)
        ->and($results->first()->contentType?->value)->toBe('table');
})->group('integration')->skip(
    fn () => blank(getenv('RAG_PG_HOST')),
    'Set RAG_PG_HOST (and RAG_PG_*) to run the pgvector search tests.',
);
