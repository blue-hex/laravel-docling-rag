<?php

namespace BlueHex\DoclingRag\Retrieval;

use BlueHex\DoclingRag\Enums\ContentType;
use BlueHex\DoclingRag\Models\RagDocument;
use Illuminate\Contracts\Support\Arrayable;

/**
 * A single retrieved chunk with its provenance and fused relevance score.
 *
 * @implements Arrayable<string, mixed>
 */
class ChunkResult implements Arrayable
{
    /**
     * @param  list<string>  $headingPath
     */
    public function __construct(
        public readonly int $id,
        public readonly int $documentId,
        public readonly string $text,
        public readonly ?int $pageNo,
        public readonly array $headingPath,
        public readonly ?ContentType $contentType,
        public float $score,
    ) {}

    /**
     * Build a result from a raw retrieval row.
     *
     * @param  array<string, mixed>|object  $row
     */
    public static function fromRow(array|object $row): self
    {
        $row = (array) $row;

        $heading = $row['heading_path'] ?? null;

        if (is_string($heading)) {
            $heading = json_decode($heading, true) ?: [];
        }

        $contentType = $row['content_type'] ?? null;

        return new self(
            id: (int) $row['id'],
            documentId: (int) $row['rag_document_id'],
            text: (string) $row['text'],
            pageNo: isset($row['page_no']) ? (int) $row['page_no'] : null,
            headingPath: is_array($heading) ? array_values($heading) : [],
            contentType: $contentType ? ContentType::tryFrom((string) $contentType) : null,
            score: (float) ($row['score'] ?? 0.0),
        );
    }

    /**
     * The document this chunk belongs to.
     */
    public function document(): ?RagDocument
    {
        return RagDocument::query()->find($this->documentId);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'document_id' => $this->documentId,
            'text' => $this->text,
            'page_no' => $this->pageNo,
            'heading_path' => $this->headingPath,
            'content_type' => $this->contentType?->value,
            'score' => $this->score,
        ];
    }
}
