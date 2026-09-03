<?php

namespace BlueHex\DoclingRag\Contracts;

use BlueHex\DoclingRag\Docling\DoclingTask;

interface ChunksDocuments
{
    /**
     * @param  string|resource  $contents
     * @param  array<string, mixed>  $options  Raw Docling request fields (convert_*, chunking_*),
     *                                         merged over config('docling-rag.docling.request_options').
     */
    public function submit(string $filename, mixed $contents, array $options = []): DoclingTask;

    public function poll(string $taskId): DoclingTask;

    /**
     * @return array<string, mixed>
     */
    public function result(string $taskId): array;
}
