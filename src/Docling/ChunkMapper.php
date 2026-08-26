<?php

namespace BlueHex\DoclingRag\Docling;

use BlueHex\DoclingRag\Contracts\MapsChunks;
use BlueHex\DoclingRag\Enums\ContentType;

class ChunkMapper implements MapsChunks
{
    public function map(array $items): array
    {
        $chunks = [];

        foreach ($items as $index => $item) {
            $text = trim((string) ($item['text'] ?? ''));

            if ($text === '') {
                continue;
            }

            $headings = $item['headings'] ?? [];
            $pageNumbers = $item['page_numbers'] ?? [];

            $chunks[] = new MappedChunk(
                ord: (int) ($item['chunk_index'] ?? $index),
                text: $text,
                headingPath: array_values(array_map(strval(...), is_array($headings) ? $headings : [])),
                pageNo: isset($pageNumbers[0]) ? (int) $pageNumbers[0] : null,
                contentType: $this->contentType($item, $text),
                tokenCount: isset($item['num_tokens']) ? (int) $item['num_tokens'] : null,
            );
        }

        return $chunks;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    protected function contentType(array $item, string $text): string
    {
        $metadata = is_array($item['metadata'] ?? null) ? $item['metadata'] : [];

        if (($metadata['has_image'] ?? false) === true) {
            return ContentType::Picture->value;
        }

        if ($this->looksLikeMarkdownTable($text)) {
            return ContentType::Table->value;
        }

        return ContentType::Text->value;
    }

    protected function looksLikeMarkdownTable(string $text): bool
    {
        return str_contains($text, '|')
            && (bool) preg_match('/^\s*\|?\s*:?-{3,}/m', $text);
    }
}
