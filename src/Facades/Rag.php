<?php

namespace BlueHex\DoclingRag\Facades;

use BlueHex\DoclingRag\Models\RagDocument;
use BlueHex\DoclingRag\Retrieval\ChunkResult;
use BlueHex\DoclingRag\Retrieval\FakeSearcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Facade;

/**
 * @method static RagDocument ingest(UploadedFile|string $file, Model $owner, ?string $disk = null)
 * @method static Collection<int, ChunkResult> search(string $query, Model $owner, array $filters = [], ?int $k = null)
 * @method static FakeSearcher fake(iterable $results = [])
 *
 * @see \BlueHex\DoclingRag\Rag
 */
class Rag extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \BlueHex\DoclingRag\Rag::class;
    }
}
