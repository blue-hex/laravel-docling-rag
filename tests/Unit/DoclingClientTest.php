<?php

use BlueHex\DoclingRag\Docling\DoclingClient;
use BlueHex\DoclingRag\Exceptions\DoclingException;
use BlueHex\DoclingRag\Tests\Fixtures\DoclingResponses;
use Illuminate\Support\Facades\Http;

it('submits an async hybrid chunk task', function () {
    Http::fake([
        'docling.test/v1/chunk/hybrid/source/async' => Http::response([
            'task_id' => 'task-99',
            'task_status' => 'pending',
        ]),
    ]);

    $task = (new DoclingClient)->submit('report.pdf', '%PDF-1.4');

    expect($task->taskId)->toBe('task-99')
        ->and($task->isPending())->toBeTrue();

    Http::assertSent(function ($request) {
        $body = $request->data();

        return str_contains($request->url(), '/v1/chunk/hybrid/source/async')
            && $body['sources'][0]['kind'] === 'file'
            && $body['sources'][0]['filename'] === 'report.pdf'
            && $body['chunking_options']['chunker'] === 'hybrid';
    });
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
        'docling.test/v1/chunk/hybrid/source/async' => Http::response(['detail' => 'nope'], 500),
    ]);

    (new DoclingClient)->submit('report.pdf', '%PDF-1.4');
})->throws(DoclingException::class);
