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

    /**
     * @param  array<string, mixed>  $options  Raw Docling request fields (convert_*, chunking_*).
     */
    public function ingestDocument(UploadedFile|string $file, ?string $disk = null, array $options = []): RagDocument
    {
        return Rag::ingest($file, owner: $this, disk: $disk, options: $options);
    }
}
