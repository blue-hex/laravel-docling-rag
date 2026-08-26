<?php

use BlueHex\DoclingRag\Contracts\EmbedsQueries;
use BlueHex\DoclingRag\Contracts\RetrievesChunks;
use BlueHex\DoclingRag\Contracts\SearchesChunks;
use BlueHex\DoclingRag\Exceptions\ModelMismatchException;
use BlueHex\DoclingRag\Models\RagChunk;
use BlueHex\DoclingRag\Models\RagDocument;
use BlueHex\DoclingRag\Retrieval\ChunkResult;
use BlueHex\DoclingRag\Tests\Fixtures\DataSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

function chunkResult(int $id, int $documentId): ChunkResult
{
    return new ChunkResult($id, $documentId, "chunk {$id}", 1, [], null, 1.0 / $id);
}

function fakeStages(Collection $retrieved): void
{
    app()->instance(EmbedsQueries::class, new class implements EmbedsQueries
    {
        public function embed(string $query): array
        {
            return [0.1, 0.2];
        }
    });

    app()->instance(RetrievesChunks::class, new class($retrieved) implements RetrievesChunks
    {
        public function __construct(protected Collection $retrieved) {}

        public function retrieve(array $vector, string $query, Model $owner, array $filters = []): Collection
        {
            return $this->retrieved;
        }
    });
}

it('returns an empty collection for a blank query', function () {
    fakeStages(new Collection([chunkResult(1, 1)]));

    $results = app(SearchesChunks::class)->search('   ', new DataSource);

    expect($results)->toBeEmpty();
});

it('caps chunks per document while preserving fused order', function () {
    config(['docling-rag.retrieval.per_document_cap' => 2]);

    fakeStages(new Collection([
        chunkResult(1, 1), chunkResult(2, 1), chunkResult(3, 1),
        chunkResult(4, 2), chunkResult(5, 2),
    ]));

    $results = app(SearchesChunks::class)->search('q', new DataSource, k: 8);

    expect($results->pluck('id')->all())->toBe([1, 2, 4, 5]);
});

it('limits results to k after capping', function () {
    config(['docling-rag.retrieval.per_document_cap' => 0]);

    fakeStages(new Collection(array_map(fn ($i) => chunkResult($i, $i), range(1, 10))));

    $results = app(SearchesChunks::class)->search('q', new DataSource, k: 3);

    expect($results)->toHaveCount(3);
});

it('refuses to search a corpus embedded with a different model', function () {
    fakeStages(new Collection([chunkResult(1, 1)]));

    $owner = DataSource::create(['name' => 'ds']);
    $document = RagDocument::factory()->create([
        'owner_type' => $owner->getMorphClass(),
        'owner_id' => $owner->getKey(),
    ]);
    RagChunk::factory()->embedded()->create([
        'rag_document_id' => $document->id,
        'embedding_model' => 'some-old-model',
    ]);

    app(SearchesChunks::class)->search('q', $owner);
})->throws(ModelMismatchException::class);
