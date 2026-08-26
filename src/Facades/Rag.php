<?php

namespace BlueHex\DoclingRag\Facades;

use BlueHex\DoclingRag\Models\RagDocument;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Facade;

/**
 * @method static RagDocument ingest(UploadedFile|string $file, Model $owner, ?string $disk = null)
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
