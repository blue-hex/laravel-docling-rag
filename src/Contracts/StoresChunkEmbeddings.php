<?php

namespace BlueHex\DoclingRag\Contracts;

use BlueHex\DoclingRag\Models\RagChunk;

interface StoresChunkEmbeddings
{
    /**
     * @param  list<float>  $embedding
     */
    public function write(RagChunk $chunk, array $embedding, string $model): void;
}
