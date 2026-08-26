<?php

namespace BlueHex\DoclingRag\Models;

use BlueHex\DoclingRag\Database\Factories\RagDocumentFactory;
use BlueHex\DoclingRag\Enums\ConvertedVia;
use BlueHex\DoclingRag\Enums\DocumentStatus;
use BlueHex\DoclingRag\Events\DocumentIngested;
use BlueHex\DoclingRag\Events\IngestionFailed;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property int $id
 * @property string $owner_type
 * @property int|string $owner_id
 * @property string $disk
 * @property string $path
 * @property string|null $mime
 * @property string $sha256
 * @property DocumentStatus $status
 * @property ConvertedVia|null $converted_via
 * @property string|null $docling_task_id
 * @property int|null $page_count
 * @property string|null $failure_reason
 */
class RagDocument extends Model
{
    /** @use HasFactory<RagDocumentFactory> */
    use HasFactory;

    protected $table = 'rag_documents';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DocumentStatus::class,
            'converted_via' => ConvertedVia::class,
            'page_count' => 'integer',
        ];
    }

    protected static function newFactory(): RagDocumentFactory
    {
        return RagDocumentFactory::new();
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return HasMany<RagChunk, $this>
     */
    public function chunks(): HasMany
    {
        return $this->hasMany(RagChunk::class);
    }

    public function markFailed(string $reason): void
    {
        $alreadyFailed = $this->status === DocumentStatus::Failed;

        $this->forceFill([
            'status' => DocumentStatus::Failed,
            'failure_reason' => $reason,
        ])->save();

        if (! $alreadyFailed) {
            IngestionFailed::dispatch($this);
        }
    }

    public function markReady(): void
    {
        $this->forceFill([
            'status' => DocumentStatus::Ready,
            'failure_reason' => null,
        ])->save();

        DocumentIngested::dispatch($this);
    }
}
