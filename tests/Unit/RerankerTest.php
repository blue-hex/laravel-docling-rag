<?php

use BlueHex\DoclingRag\Contracts\RanksChunks;
use BlueHex\DoclingRag\Retrieval\ChunkResult;
use Illuminate\Support\Collection;
use Laravel\Ai\Reranking;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\RankedDocument;
use Laravel\Ai\Responses\RerankingResponse;

function result(int $id, string $text, float $score = 0.0): ChunkResult
{
    return new ChunkResult($id, 1, $text, 1, [], null, $score);
}

it('reorders results by the reranker and stamps the new score', function () {
    Reranking::fake(fn () => new RerankingResponse([
        new RankedDocument(index: 2, document: 'c', score: 0.9),
        new RankedDocument(index: 0, document: 'a', score: 0.4),
        new RankedDocument(index: 1, document: 'b', score: 0.1),
    ], new Meta('fake', 'fake-rerank')));

    $results = new Collection([result(10, 'a'), result(11, 'b'), result(12, 'c')]);

    $ranked = app(RanksChunks::class)->rank('query', $results, 30);

    expect($ranked->pluck('id')->all())->toBe([12, 10, 11])
        ->and($ranked->first()->score)->toBe(0.9);
});

it('only reranks the top window', function () {
    Reranking::fake();

    $results = new Collection(array_map(fn ($i) => result($i, "chunk {$i}"), range(1, 40)));

    app(RanksChunks::class)->rank('query', $results, 5);

    Reranking::assertReranked(fn ($prompt) => count($prompt->documents) === 5);
});

it('passes through without a provider call when fewer than two results', function () {
    Reranking::fake();

    $ranked = app(RanksChunks::class)->rank('query', new Collection([result(1, 'only')]), 30);

    expect($ranked->pluck('id')->all())->toBe([1]);
    Reranking::assertNothingReranked();
});
