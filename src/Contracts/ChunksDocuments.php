<?php

namespace BlueHex\DoclingRag\Contracts;

use BlueHex\DoclingRag\Docling\DoclingTask;

interface ChunksDocuments
{
    public function submit(string $filename, string $contents): DoclingTask;

    public function poll(string $taskId): DoclingTask;

    /**
     * @return array<string, mixed>
     */
    public function result(string $taskId): array;
}
