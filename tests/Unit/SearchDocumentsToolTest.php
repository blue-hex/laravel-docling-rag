<?php

use BlueHex\DoclingRag\Enums\ContentType;
use BlueHex\DoclingRag\Facades\Rag;
use BlueHex\DoclingRag\Retrieval\ChunkResult;
use BlueHex\DoclingRag\Tests\Fixtures\DataSource;
use BlueHex\DoclingRag\Tools\SearchDocuments;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Tools\Request;

it('formats results with page and heading citations', function () {
    Rag::fake([
        new ChunkResult(1, 7, 'Refunds within 30 days.', 4, ['Policies', 'Refunds'], ContentType::Text, 0.9),
    ]);

    $tool = SearchDocuments::for(new DataSource);

    $output = $tool->handle(new Request(['query' => 'refund policy']));

    expect($output)
        ->toContain('page 4')
        ->toContain('Policies › Refunds')
        ->toContain('Refunds within 30 days.');
});

it('tells the model to rephrase when nothing is found', function () {
    Rag::fake([]);

    $output = SearchDocuments::for(new DataSource)->handle(new Request(['query' => 'x']));

    expect($output)->toContain('No relevant passages found');
});

it('passes the owner, filters and k through to search', function () {
    $fake = Rag::fake([]);
    $owner = new DataSource(['id' => 3]);

    SearchDocuments::for($owner, ['content_type' => ContentType::Table], 5)
        ->handle(new Request(['query' => 'quarterly table']));

    expect($fake->searches)->toHaveCount(1)
        ->and($fake->searches[0]['query'])->toBe('quarterly table')
        ->and($fake->searches[0]['filters'])->toBe(['content_type' => ContentType::Table])
        ->and($fake->searches[0]['k'])->toBe(5);
});

it('exposes a required query in its schema', function () {
    $schema = SearchDocuments::for(new DataSource)->schema(new JsonSchemaTypeFactory);

    expect($schema)->toHaveKey('query');
});
