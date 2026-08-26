<?php

namespace BlueHex\DoclingRag\Contracts;

use BlueHex\DoclingRag\Docling\MappedChunk;

interface MapsChunks
{
    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<MappedChunk>
     */
    public function map(array $items): array;
}
