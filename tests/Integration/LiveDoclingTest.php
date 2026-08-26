<?php

use BlueHex\DoclingRag\Docling\DoclingClient;
use Illuminate\Support\Facades\Http;

it('matches the live Docling hybrid chunk contract', function () {
    $url = rtrim((string) getenv('DOCLING_LIVE_URL'), '/');

    config(['docling-rag.docling.url' => $url]);

    Http::allowStrayRequests();

    $client = new DoclingClient;
    $task = $client->submit('hello.md', "# Hello\n\nThis is a tiny live-contract document.");

    expect($task->taskId)->not->toBe('');

    $status = $client->poll($task->taskId);
    $attempts = 0;

    while ($status->isPending() && $attempts < 30) {
        usleep(200_000);
        $status = $client->poll($task->taskId);
        $attempts++;
    }

    expect($status->isSuccess())->toBeTrue();

    $result = $client->result($task->taskId);

    expect($result['chunks'] ?? [])->not->toBeEmpty();
})->group('integration')->skip(
    fn () => blank(getenv('DOCLING_LIVE_URL')),
    'Set DOCLING_LIVE_URL to run the live Docling contract test.',
);
