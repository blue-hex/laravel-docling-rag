<?php

namespace BlueHex\DoclingRag\Docling;

use BlueHex\DoclingRag\Contracts\ChunksDocuments;
use BlueHex\DoclingRag\Exceptions\DoclingException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class DoclingClient implements ChunksDocuments
{
    /**
     * @param  string|resource  $contents
     * @param  array<string, mixed>  $options
     */
    public function submit(string $filename, mixed $contents, array $options = []): DoclingTask
    {
        $uploadTimeout = (int) config('docling-rag.docling.upload_timeout', config('docling-rag.docling.timeout', 120));

        $response = $this->http($uploadTimeout)
            ->attach('files', $contents, $filename)
            ->post('/v1/chunk/hybrid/file/async', $this->requestFields($options));

        if ($response->failed()) {
            throw new DoclingException('Docling rejected the conversion request: '.$response->body());
        }

        $task = DoclingTask::fromArray($response->json() ?? []);

        if ($task->taskId === '') {
            throw new DoclingException('Docling did not return a task_id.');
        }

        return $task;
    }

    public function poll(string $taskId): DoclingTask
    {
        $response = $this->http()->get("/v1/status/poll/{$taskId}", [
            'wait' => 0,
        ]);

        if ($response->failed()) {
            throw new DoclingException("Docling poll failed for task [{$taskId}]: ".$response->body());
        }

        return DoclingTask::fromArray($response->json() ?? []);
    }

    public function result(string $taskId): array
    {
        $response = $this->http()->get("/v1/result/{$taskId}");

        if ($response->failed()) {
            throw new DoclingException("Docling result fetch failed for task [{$taskId}]: ".$response->body());
        }

        return $response->json() ?? [];
    }

    /**
     * Form fields for the multipart /v1/chunk/hybrid/file/async request.
     * $options wins over config('docling-rag.docling.request_options'); both
     * use Docling's own field names (convert_*, chunking_*) verbatim.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, string>
     */
    protected function requestFields(array $options): array
    {
        $merged = array_merge(
            (array) config('docling-rag.docling.request_options', []),
            $options
        );

        $fields = [];

        foreach ($merged as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $fields[$key] = match (true) {
                is_bool($value) => $value ? 'true' : 'false',
                is_array($value) => json_encode($value),
                default => (string) $value,
            };
        }

        return $fields;
    }

    protected function http(?int $timeout = null): PendingRequest
    {
        $request = Http::baseUrl(rtrim((string) config('docling-rag.docling.url'), '/'))
            ->timeout($timeout ?? (int) config('docling-rag.docling.timeout', 120))
            ->acceptJson();

        $apiKey = config('docling-rag.docling.api_key');

        if (is_string($apiKey) && $apiKey !== '') {
            $request = $request->withHeader('X-Api-Key', $apiKey);
        }

        return $request;
    }
}
