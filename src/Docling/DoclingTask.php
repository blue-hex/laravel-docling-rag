<?php

namespace BlueHex\DoclingRag\Docling;

final readonly class DoclingTask
{
    public function __construct(
        public string $taskId,
        public string $status,
        public ?string $errorMessage = null,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            taskId: (string) ($payload['task_id'] ?? ''),
            status: (string) ($payload['task_status'] ?? 'pending'),
            errorMessage: isset($payload['error_message']) ? (string) $payload['error_message'] : null,
        );
    }

    public function isPending(): bool
    {
        return in_array($this->status, ['pending', 'started'], true);
    }

    public function isSuccess(): bool
    {
        return $this->status === 'success';
    }

    public function isFailure(): bool
    {
        return $this->status === 'failure';
    }
}
