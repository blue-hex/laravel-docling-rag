<?php

use BlueHex\DoclingRag\Docling\ChunkMapper;
use BlueHex\DoclingRag\Enums\ContentType;
use BlueHex\DoclingRag\Tests\Fixtures\DoclingResponses;

it('maps Docling chunk provenance onto rag_chunks fields', function () {
    $chunks = (new ChunkMapper)->map(DoclingResponses::chunks());

    expect($chunks)->toHaveCount(3)
        ->and($chunks[0]->ord)->toBe(0)
        ->and($chunks[0]->headingPath)->toBe(['Introduction'])
        ->and($chunks[0]->pageNo)->toBe(1)
        ->and($chunks[0]->contentType)->toBe(ContentType::Text->value)
        ->and($chunks[0]->tokenCount)->toBe(12)
        ->and($chunks[1]->contentType)->toBe(ContentType::Table->value)
        ->and($chunks[2]->contentType)->toBe(ContentType::Picture->value)
        ->and($chunks[2]->headingPath)->toBe(['Results', 'Figures']);
});

it('skips empty chunk text', function () {
    $chunks = (new ChunkMapper)->map([
        ['chunk_index' => 0, 'text' => '  ', 'headings' => [], 'page_numbers' => []],
        ['chunk_index' => 1, 'text' => 'Kept', 'headings' => ['A'], 'page_numbers' => [4]],
    ]);

    expect($chunks)->toHaveCount(1)
        ->and($chunks[0]->ord)->toBe(1)
        ->and($chunks[0]->pageNo)->toBe(4);
});
