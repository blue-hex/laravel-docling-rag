<?php

namespace BlueHex\DoclingRag\Conversion;

final readonly class ConvertedFile
{
    public function __construct(
        public string $filename,
        public string $contents,
    ) {}
}
