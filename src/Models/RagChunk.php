<?php

namespace BlueHex\DoclingRag\Models;

use BlueHex\DoclingRag\Database\Factories\RagChunkFactory;
use BlueHex\DoclingRag\Enums\ContentType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $rag_document_id
 * @property int $ord
 * @property int|null $parent_id
 * @property string $text
 * @property list<string>|null $heading_path
 * @property int|null $page_no
 * @property ContentType|null $content_type
 * @property int|null $token_count
 * @property list<float>|null $embedding
 * @property string|null $embedding_model
 * @property Carbon|null $embedded_at
 */
class RagChunk extends Model
{
    /** @use HasFactory<RagChunkFactory> */
    use HasFactory;

    protected $table = 'rag_chunks';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'heading_path' => 'array',
            'embedding' => 'array',
            'embedded_at' => 'datetime',
            'content_type' => ContentType::class,
            'page_no' => 'integer',
            'token_count' => 'integer',
            'ord' => 'integer',
        ];
    }

    protected static function newFactory(): RagChunkFactory
    {
        return RagChunkFactory::new();
    }

    /**
     * @return BelongsTo<RagDocument, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(RagDocument::class, 'rag_document_id');
    }
}
