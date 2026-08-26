<?php

namespace BlueHex\DoclingRag\Jobs;

use BlueHex\DoclingRag\Contracts\ChunksDocuments;
use BlueHex\DoclingRag\Contracts\ConvertsUnsupportedFormats;
use BlueHex\DoclingRag\Contracts\RoutesFormats;
use BlueHex\DoclingRag\Enums\ConversionPath;
use BlueHex\DoclingRag\Enums\ConvertedVia;
use BlueHex\DoclingRag\Enums\DocumentStatus;
use BlueHex\DoclingRag\Exceptions\GotenbergException;
use BlueHex\DoclingRag\Exceptions\UnsupportedFormatException;
use BlueHex\DoclingRag\Models\RagDocument;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Throwable;

class IngestDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [5, 15, 30];
    }

    public function __construct(public int $documentId) {}

    public function handle(
        RoutesFormats $router,
        ConvertsUnsupportedFormats $converter,
        ChunksDocuments $docling,
    ): void {
        $document = RagDocument::query()->findOrFail($this->documentId);

        try {
            $contents = Storage::disk($document->disk)->get($document->path);

            if ($contents === null) {
                $document->markFailed("Source file [{$document->path}] is missing.");

                return;
            }

            $filename = basename($document->path);
            $path = $router->route($filename, $document->mime);

            if ($path === ConversionPath::Gotenberg) {
                $document->update([
                    'status' => DocumentStatus::Converting,
                    'converted_via' => ConvertedVia::Gotenberg,
                ]);

                $converted = $converter->convert($filename, $contents);
                $filename = $converted->filename;
                $contents = $converted->contents;
            } else {
                $document->update([
                    'status' => DocumentStatus::Converting,
                    'converted_via' => ConvertedVia::Native,
                ]);
            }

            $document->update(['status' => DocumentStatus::Chunking]);

            $task = $docling->submit($filename, $contents);

            $document->update(['docling_task_id' => $task->taskId]);

            PollDoclingTaskJob::dispatch($document->id, 0);
        } catch (UnsupportedFormatException|GotenbergException $exception) {
            $document->markFailed($exception->getMessage());
        }
    }

    public function failed(?Throwable $exception): void
    {
        RagDocument::query()->find($this->documentId)?->markFailed(
            $exception?->getMessage() ?? 'Ingestion job failed.'
        );
    }
}
