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

    /**
     * @param  array<string, mixed>  $doclingOptions  Raw Docling request fields (convert_*, chunking_*),
     *                                                merged over config('docling-rag.docling.request_options').
     */
    public function __construct(public int $documentId, public array $doclingOptions = []) {}

    public function handle(
        RoutesFormats $router,
        ConvertsUnsupportedFormats $converter,
        ChunksDocuments $docling,
    ): void {
        $document = RagDocument::query()->findOrFail($this->documentId);

        try {
            $disk = Storage::disk($document->disk);

            if (! $disk->exists($document->path)) {
                $document->markFailed("Source file [{$document->path}] is missing.");

                return;
            }

            $maxUploadMb = (int) config('docling-rag.limits.max_upload_mb', 100);
            $sizeBytes = $disk->size($document->path);

            if ($maxUploadMb > 0 && $sizeBytes > $maxUploadMb * 1024 * 1024) {
                $document->markFailed(
                    sprintf('Source file is %.1f MB; limit is %d MB.', $sizeBytes / (1024 * 1024), $maxUploadMb)
                );

                return;
            }

            $filename = basename($document->path);
            $path = $router->route($filename, $document->mime);

            if ($path === ConversionPath::Gotenberg) {
                $document->update([
                    'status' => DocumentStatus::Converting,
                    'converted_via' => ConvertedVia::Gotenberg,
                ]);

                $contents = $disk->get($document->path);

                if ($contents === null) {
                    $document->markFailed("Source file [{$document->path}] is missing.");

                    return;
                }

                $converted = $converter->convert($filename, $contents);
                $filename = $converted->filename;
                $submission = $converted->contents;
            } else {
                $document->update([
                    'status' => DocumentStatus::Converting,
                    'converted_via' => ConvertedVia::Native,
                ]);

                // Rewrite extensions Docling wouldn't recognize as-is (e.g.
                // .markdown -> .md) before it sees the filename.
                $filename = $router->normalizeFilename($filename);

                // Stream straight from disk instead of buffering the whole file
                // into a PHP string — keeps large uploads out of worker memory.
                $submission = $disk->readStream($document->path);

                if ($submission === null) {
                    $document->markFailed("Source file [{$document->path}] is missing.");

                    return;
                }
            }

            $document->update(['status' => DocumentStatus::Chunking]);

            $task = $docling->submit($filename, $submission, $this->doclingOptions);

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
