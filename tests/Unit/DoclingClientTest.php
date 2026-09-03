<?php

use BlueHex\DoclingRag\Docling\DoclingClient;
use BlueHex\DoclingRag\Exceptions\DoclingException;
use BlueHex\DoclingRag\Tests\Fixtures\DoclingResponses;
use Illuminate\Support\Facades\Http;

function multipartFieldValue(string $body, string $name): ?string
{
    foreach (preg_split('/\r\n--[^\r\n]+\r\n?/', $body) as $part) {
        if (str_contains($part, 'name="'.$name.'"')) {
            [, $value] = explode("\r\n\r\n", $part, 2) + [null, null];

            return $value === null ? null : rtrim($value);
        }
    }

    return null;
}

it('submits an async hybrid chunk task as a multipart file upload', function () {
    Http::fake([
        'docling.test/v1/chunk/hybrid/file/async' => Http::response([
            'task_id' => 'task-99',
            'task_status' => 'pending',
        ]),
    ]);

    $task = (new DoclingClient)->submit('report.pdf', '%PDF-1.4');

    expect($task->taskId)->toBe('task-99')
        ->and($task->isPending())->toBeTrue();

    Http::assertSent(function ($request) {
        $body = (string) $request->body();

        return str_contains($request->url(), '/v1/chunk/hybrid/file/async')
            && $request->hasHeader('Content-Type')
            && str_contains($request->header('Content-Type')[0], 'multipart/form-data')
            && str_contains($body, 'name="files"')
            && str_contains($body, 'filename="report.pdf"')
            && str_contains($body, '%PDF-1.4')
            && str_contains($body, 'name="chunking_max_tokens"');
    });
});

it('streams a resource straight through as the multipart file body', function () {
    Http::fake([
        'docling.test/v1/chunk/hybrid/file/async' => Http::response([
            'task_id' => 'task-100',
            'task_status' => 'pending',
        ]),
    ]);

    $stream = fopen('php://temp', 'r+');
    fwrite($stream, '%PDF-stream');
    rewind($stream);

    $task = (new DoclingClient)->submit('streamed.pdf', $stream);

    expect($task->taskId)->toBe('task-100');

    Http::assertSent(function ($request) {
        $body = (string) $request->body();

        return str_contains($body, 'filename="streamed.pdf"')
            && str_contains($body, '%PDF-stream');
    });
});

it('sends the configured default request options', function () {
    config(['docling-rag.docling.request_options' => [
        'convert_do_ocr' => true,
        'chunking_max_tokens' => 256,
        'chunking_merge_peers' => false,
        'chunking_tokenizer' => null,
    ]]);

    Http::fake([
        'docling.test/v1/chunk/hybrid/file/async' => Http::response([
            'task_id' => 'task-101',
            'task_status' => 'pending',
        ]),
    ]);

    (new DoclingClient)->submit('report.pdf', '%PDF-1.4');

    Http::assertSent(function ($request) {
        $body = (string) $request->body();

        return multipartFieldValue($body, 'convert_do_ocr') === 'true'
            && multipartFieldValue($body, 'chunking_max_tokens') === '256'
            && multipartFieldValue($body, 'chunking_merge_peers') === 'false'
            && ! str_contains($body, 'chunking_tokenizer');
    });
});

it('lets a per-call option override the configured default', function () {
    config(['docling-rag.docling.request_options' => [
        'convert_do_ocr' => true,
    ]]);

    Http::fake([
        'docling.test/v1/chunk/hybrid/file/async' => Http::response([
            'task_id' => 'task-102',
            'task_status' => 'pending',
        ]),
    ]);

    (new DoclingClient)->submit('report.pdf', '%PDF-1.4', ['convert_do_ocr' => false]);

    Http::assertSent(fn ($request) => multipartFieldValue((string) $request->body(), 'convert_do_ocr') === 'false');
});

it('polls task status and fetches the result payload', function () {
    Http::fake([
        'docling.test/v1/status/poll/*' => Http::response([
            'task_id' => 'task-99',
            'task_status' => 'success',
        ]),
        'docling.test/v1/result/*' => Http::response(DoclingResponses::result()),
    ]);

    $client = new DoclingClient;

    expect($client->poll('task-99')->isSuccess())->toBeTrue()
        ->and($client->result('task-99')['chunks'])->toHaveCount(3);
});

it('throws when Docling rejects the submit', function () {
    Http::fake([
        'docling.test/v1/chunk/hybrid/file/async' => Http::response(['detail' => 'nope'], 500),
    ]);

    (new DoclingClient)->submit('report.pdf', '%PDF-1.4');
})->throws(DoclingException::class);
