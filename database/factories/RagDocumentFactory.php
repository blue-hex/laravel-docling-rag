<?php

namespace BlueHex\DoclingRag\Database\Factories;

use BlueHex\DoclingRag\Enums\ConvertedVia;
use BlueHex\DoclingRag\Enums\DocumentStatus;
use BlueHex\DoclingRag\Models\RagDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RagDocument>
 */
class RagDocumentFactory extends Factory
{
    protected $model = RagDocument::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'owner_type' => 'App\\Models\\DataSource',
            'owner_id' => 1,
            'disk' => 'local',
            'path' => 'rag/1/'.fake()->sha256().'/report.pdf',
            'mime' => 'application/pdf',
            'sha256' => fake()->sha256(),
            'status' => DocumentStatus::Pending,
            'converted_via' => ConvertedVia::Native,
        ];
    }

    public function ready(): static
    {
        return $this->state(fn (): array => [
            'status' => DocumentStatus::Ready,
        ]);
    }

    public function failed(string $reason = 'boom'): static
    {
        return $this->state(fn (): array => [
            'status' => DocumentStatus::Failed,
            'failure_reason' => $reason,
        ]);
    }
}
