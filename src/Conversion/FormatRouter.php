<?php

namespace BlueHex\DoclingRag\Conversion;

use BlueHex\DoclingRag\Contracts\RoutesFormats;
use BlueHex\DoclingRag\Enums\ConversionPath;
use BlueHex\DoclingRag\Exceptions\UnsupportedFormatException;

class FormatRouter implements RoutesFormats
{
    /**
     * @var list<string>
     */
    public const NATIVE_EXTENSIONS = [
        'pdf', 'docx', 'pptx', 'xlsx', 'html', 'htm', 'md', 'markdown',
        'asciidoc', 'adoc', 'doc', 'ppt', 'xls', 'odt', 'ods', 'odp',
        'csv', 'epub',
        'png', 'jpg', 'jpeg', 'tif', 'tiff', 'gif', 'webp', 'bmp',
    ];

    /**
     * @var list<string>
     */
    public const GOTENBERG_EXTENSIONS = [
        'rtf', 'txt', 'wpd', 'wps', 'pub', 'vsd', 'fodt', 'fods', 'fodp',
    ];

    public function route(string $filename, ?string $mime = null): ConversionPath
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if (in_array($extension, self::NATIVE_EXTENSIONS, true)) {
            return ConversionPath::Native;
        }

        $gotenbergCandidate = in_array($extension, self::GOTENBERG_EXTENSIONS, true)
            || $this->looksLikeOfficeMime($mime);

        if ($gotenbergCandidate) {
            if (! config('docling-rag.gotenberg.enabled')) {
                throw new UnsupportedFormatException(
                    "Format [{$extension}] is not a native Docling format and Gotenberg is disabled."
                );
            }

            return ConversionPath::Gotenberg;
        }

        throw new UnsupportedFormatException(
            "Format [{$extension}] is not supported for ingestion."
        );
    }

    protected function looksLikeOfficeMime(?string $mime): bool
    {
        if ($mime === null) {
            return false;
        }

        return str_contains($mime, 'msword')
            || str_contains($mime, 'officedocument')
            || str_contains($mime, 'ms-excel')
            || str_contains($mime, 'ms-powerpoint')
            || str_contains($mime, 'opendocument');
    }
}
