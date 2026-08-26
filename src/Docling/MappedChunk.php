<?php

namespace BlueHex\DoclingRag\Docling;

final readonly class MappedChunk
{
    /**
     * @param  list<string>  $headingPath
     */
    public function __construct(
        public int $ord,
        public string $text,
        public array $headingPath,
        public ?int $pageNo,
        public string $contentType,
        public ?int $tokenCount,
    ) {}
}
