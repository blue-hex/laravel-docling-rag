<?php

namespace BlueHex\DoclingRag\Support;

use BlueHex\DoclingRag\Facades\Rag;
use BlueHex\DoclingRag\Models\RagDocument;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Http\UploadedFile;

/**
 * @mixin Model
 */
trait HasRagDocuments
{
    /**
     * @return MorphMany<RagDocument, $this>
     */
    public function ragDocuments(): MorphMany
    {
        return $this->morphMany(RagDocument::class, 'owner');
    }

    public function ingestDocument(UploadedFile|string $file, ?string $disk = null): RagDocument
    {
        return Rag::ingest($file, owner: $this, disk: $disk);
    }
}
