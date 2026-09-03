<?php

namespace BlueHex\DoclingRag\Jobs;

use BlueHex\DoclingRag\Contracts\ChunksDocuments;
use BlueHex\DoclingRag\Contracts\MapsChunks;
use BlueHex\DoclingRag\Docling\MappedChunk;
use BlueHex\DoclingRag\Enums\DocumentStatus;
use BlueHex\DoclingRag\Models\RagChunk;
use BlueHex\DoclingRag\Models\RagDocument;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class PollDoclingTaskJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public int $documentId, public int $attempt = 0) {}

    public function handle(ChunksDocuments $docling, MapsChunks $mapper): void
    {
        $document = RagDocument::query()->findOrFail($this->documentId);

        if (! $document->docling_task_id) {
            $document->markFailed('Missing Docling task id.');

            return;
        }

        $task = $docling->poll($document->docling_task_id);

        if ($task->isPending()) {
            $maxAttempts = (int) config('docling-rag.docling.poll.max_attempts', 30);

            if ($this->attempt + 1 >= $maxAttempts) {
                $document->markFailed("Docling task timed out after {$maxAttempts} poll attempts.");

                return;
            }

            static::dispatch($document->id, $this->attempt + 1)
                ->delay(now()->addSeconds($this->delaySeconds()));

            return;
        }

        if ($task->isFailure()) {
            $document->markFailed($task->errorMessage ?: 'Docling task failed.');

            return;
        }

        $payload = $docling->result($document->docling_task_id);
        $chunks = $mapper->map($payload['chunks'] ?? []);

        if ($chunks === []) {
            $document->markFailed($this->documentErrorMessage($payload) ?? 'Docling returned no chunks.');

            return;
        }

        $pageCount = $this->pageCount($chunks);
        $maxPages = (int) config('docling-rag.limits.max_pages', 200);
        $maxChunks = (int) config('docling-rag.limits.max_chunks', 4000);

        $document->update(['page_count' => $pageCount]);

        if ($maxPages > 0 && $pageCount > $maxPages) {
            $document->markFailed("Document has {$pageCount} pages; limit is {$maxPages}.");

            return;
        }

        if ($maxChunks > 0 && count($chunks) > $maxChunks) {
            $document->markFailed('Document produced '.count($chunks)." chunks; limit is {$maxChunks}.");

            return;
        }

        $document->chunks()->delete();

        foreach ($chunks as $chunk) {
            RagChunk::query()->create([
                'rag_document_id' => $document->id,
                'ord' => $chunk->ord,
                'parent_id' => null,
                'text' => $chunk->text,
                'heading_path' => $chunk->headingPath,
                'page_no' => $chunk->pageNo,
                'content_type' => $chunk->contentType,
                'token_count' => $chunk->tokenCount,
            ]);
        }

        $document->update(['status' => DocumentStatus::Embedding]);

        EmbedChunksJob::dispatch($document->id);
    }

    public function failed(?Throwable $exception): void
    {
        RagDocument::query()->find($this->documentId)?->markFailed(
            $exception?->getMessage() ?? 'Docling poll job failed.'
        );
    }

    /**
     * Surface Docling's own per-document error (e.g. "File format not
     * allowed: notes.markdown") instead of a generic "no chunks" message
     * when the task itself reports success but skipped every document.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function documentErrorMessage(array $payload): ?string
    {
        $messages = [];

        foreach ((array) ($payload['documents'] ?? []) as $doc) {
            if (! is_array($doc)) {
                continue;
            }

            foreach ((array) ($doc['errors'] ?? []) as $error) {
                $message = is_array($error) ? ($error['error_message'] ?? null) : null;

                if (is_string($message) && $message !== '') {
                    $messages[] = $message;
                }
            }
        }

        $messages = array_unique($messages);

        return $messages === [] ? null : implode(' ', $messages);
    }

    /**
     * @param  list<MappedChunk>  $chunks
     */
    protected function pageCount(array $chunks): int
    {
        $pages = array_filter(array_map(fn (MappedChunk $chunk): ?int => $chunk->pageNo, $chunks));

        return $pages === [] ? 0 : (int) max($pages);
    }

    protected function delaySeconds(): int
    {
        $backoff = config('docling-rag.docling.poll.backoff', [5, 10, 20, 40, 60]);
        $index = min($this->attempt, count($backoff) - 1);

        return (int) $backoff[$index];
    }
}
