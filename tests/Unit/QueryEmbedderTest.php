<?php

use BlueHex\DoclingRag\Contracts\EmbedsQueries;
use Laravel\Ai\Embeddings;

it('embeds a query with the configured dimensions', function () {
    config(['docling-rag.embedding.dimensions' => 4]);

    Embeddings::fake(fn () => [[0.1, 0.2, 0.3, 0.4]]);

    $vector = app(EmbedsQueries::class)->embed('what is the refund policy?');

    expect($vector)->toBe([0.1, 0.2, 0.3, 0.4]);

    Embeddings::assertGenerated(
        fn ($prompt) => $prompt->inputs === ['what is the refund policy?'] && $prompt->dimensions === 4
    );
});

it('caches the query embedding by hash for the configured ttl', function () {
    config(['docling-rag.retrieval.cache_ttl' => 300]);

    $calls = 0;
    Embeddings::fake(function () use (&$calls) {
        $calls++;

        return [[0.5, 0.5]];
    });

    $embedder = app(EmbedsQueries::class);
    $embedder->embed('same query');
    $embedder->embed('same query');

    expect($calls)->toBe(1);
});

it('bypasses the cache when ttl is zero', function () {
    config(['docling-rag.retrieval.cache_ttl' => 0]);

    $calls = 0;
    Embeddings::fake(function () use (&$calls) {
        $calls++;

        return [[0.5, 0.5]];
    });

    $embedder = app(EmbedsQueries::class);
    $embedder->embed('q');
    $embedder->embed('q');

    expect($calls)->toBe(2);
});
