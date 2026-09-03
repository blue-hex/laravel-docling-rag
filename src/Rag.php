<?php

namespace BlueHex\DoclingRag;

use BlueHex\DoclingRag\Contracts\SearchesChunks;
use BlueHex\DoclingRag\Enums\DocumentStatus;
use BlueHex\DoclingRag\Exceptions\IngestionException;
use BlueHex\DoclingRag\Jobs\IngestDocumentJob;
use BlueHex\DoclingRag\Models\RagDocument;
use BlueHex\DoclingRag\Retrieval\ChunkResult;
use BlueHex\DoclingRag\Retrieval\FakeSearcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class Rag
{
    /**
     * Run hybrid search over an owner's corpus.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, ChunkResult>
     */
    public function search(string $query, Model $owner, array $filters = [], ?int $k = null): Collection
    {
        return app(SearchesChunks::class)->search($query, $owner, $filters, $k);
    }

    /**
     * Swap search for a fake returning canned results. For host agent tests.
     *
     * @param  iterable<int, ChunkResult>  $results
     */
    public static function fake(iterable $results = []): FakeSearcher
    {
        $fake = new FakeSearcher($results);

        app()->instance(SearchesChunks::class, $fake);

        return $fake;
    }

    /**
     * @param  array<string, mixed>  $options  Raw Docling request fields (convert_*, chunking_*),
     *                                          merged over config('docling-rag.docling.request_options').
     */
    public function ingest(UploadedFile|string $file, Model $owner, ?string $disk = null, array $options = []): RagDocument
    {
        $disk ??= (string) config('docling-rag.storage.disk', 'local');
        $prefix = trim((string) config('docling-rag.storage.path', 'rag'), '/');

        [$contents, $originalName, $mime] = $this->readFile($file);
        $sha256 = hash('sha256', $contents);

        $existing = RagDocument::query()
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getKey())
            ->where('sha256', $sha256)
            ->first();

        if ($existing) {
            if ($existing->status === DocumentStatus::Ready || $existing->status->isInFlight()) {
                return $existing;
            }

            if ($existing->status === DocumentStatus::Failed) {
                $existing->forceFill([
                    'status' => DocumentStatus::Pending,
                    'failure_reason' => null,
                    'docling_task_id' => null,
                ])->save();

                IngestDocumentJob::dispatch($existing->id, $options);

                return $existing->refresh();
            }
        }

        $path = $prefix.'/'.$owner->getKey().'/'.$sha256.'/'.$originalName;

        Storage::disk($disk)->put($path, $contents);

        $document = RagDocument::query()->create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getKey(),
            'disk' => $disk,
            'path' => $path,
            'mime' => $mime,
            'sha256' => $sha256,
            'status' => DocumentStatus::Pending,
        ]);

        IngestDocumentJob::dispatch($document->id, $options);

        return $document;
    }

    /**
     * @return array{0: string, 1: string, 2: ?string}
     */
    protected function readFile(UploadedFile|string $file): array
    {
        if ($file instanceof UploadedFile) {
            $path = $file->getRealPath();

            if ($path === false) {
                throw new IngestionException('Unable to read the uploaded file.');
            }

            $contents = file_get_contents($path);

            if ($contents === false) {
                throw new IngestionException('Unable to read the uploaded file.');
            }

            return [
                $contents,
                $file->getClientOriginalName(),
                $file->getMimeType() ?: $file->getClientMimeType(),
            ];
        }

        $contents = @file_get_contents($file);

        if ($contents === false) {
            throw new IngestionException("Unable to read file [{$file}].");
        }

        return [
            $contents,
            basename($file),
            mime_content_type($file) ?: null,
        ];
    }
}
