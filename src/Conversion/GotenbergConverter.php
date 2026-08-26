<?php

namespace BlueHex\DoclingRag\Conversion;

use BlueHex\DoclingRag\Contracts\ConvertsUnsupportedFormats;
use BlueHex\DoclingRag\Exceptions\GotenbergException;
use Gotenberg\Gotenberg;
use Gotenberg\Stream;

class GotenbergConverter implements ConvertsUnsupportedFormats
{
    public function convert(string $filename, string $contents): ConvertedFile
    {
        $request = Gotenberg::libreOffice((string) config('docling-rag.gotenberg.url'))
            ->convert(Stream::string($filename, $contents));

        $response = Gotenberg::send($request);
        $body = $response->getBody()->getContents();
        $contentType = $response->getHeaderLine('Content-Type');

        if (str_contains($contentType, 'application/zip') || str_starts_with($body, 'PK')) {
            throw new GotenbergException('Gotenberg returned a ZIP archive; v1 supports a single file only.');
        }

        return new ConvertedFile(
            filename: pathinfo($filename, PATHINFO_FILENAME).'.pdf',
            contents: $body,
        );
    }
}
