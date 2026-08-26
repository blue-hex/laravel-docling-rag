<?php

namespace BlueHex\DoclingRag\Docling;

use BlueHex\DoclingRag\Contracts\ChunksDocuments;
use BlueHex\DoclingRag\Exceptions\DoclingException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class DoclingClient implements ChunksDocuments
{
    public function submit(string $filename, string $contents): DoclingTask
    {
        $response = $this->http()->post('/v1/chunk/hybrid/source/async', [
            'sources' => [[
                'kind' => 'file',
                'base64_string' => base64_encode($contents),
                'filename' => $filename,
            ]],
            'chunking_options' => $this->chunkingOptions(),
        ]);

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
     * @return array<string, mixed>
     */
    protected function chunkingOptions(): array
    {
        $options = [
            'chunker' => 'hybrid',
            'max_tokens' => (int) config('docling-rag.docling.chunking.max_tokens', 512),
            'merge_peers' => (bool) config('docling-rag.docling.chunking.merge_peers', true),
            'use_markdown_tables' => (bool) config('docling-rag.docling.chunking.use_markdown_tables', true),
        ];

        $tokenizer = config('docling-rag.docling.chunking.tokenizer');

        if (is_string($tokenizer) && $tokenizer !== '') {
            $options['tokenizer'] = $tokenizer;
        }

        return $options;
    }

    protected function http(): PendingRequest
    {
        $request = Http::baseUrl(rtrim((string) config('docling-rag.docling.url'), '/'))
            ->timeout((int) config('docling-rag.docling.timeout', 120))
            ->acceptJson();

        $apiKey = config('docling-rag.docling.api_key');

        if (is_string($apiKey) && $apiKey !== '') {
            $request = $request->withHeader('X-Api-Key', $apiKey);
        }

        return $request;
    }
}
