<?php

namespace BlueHex\DoclingRag\Contracts;

use BlueHex\DoclingRag\Enums\ConversionPath;

interface RoutesFormats
{
    public function route(string $filename, ?string $mime = null): ConversionPath;

    /**
     * Rewrite the filename's extension if the destination converter wouldn't
     * recognize it as-is (e.g. Docling only accepts .md, not .markdown).
     */
    public function normalizeFilename(string $filename): string;
}
