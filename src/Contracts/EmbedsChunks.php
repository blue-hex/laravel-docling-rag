<?php

namespace BlueHex\DoclingRag\Contracts;

use BlueHex\DoclingRag\Models\RagChunk;
use Illuminate\Support\Collection;

interface EmbedsChunks
{
    /**
     * @param  Collection<int, RagChunk>  $chunks
     */
    public function embed(Collection $chunks): void;
}
